<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encaissements des clients (plan de parité fripv2, phase 4.4 : « Encaissement d'un client ») : l'argent reçu d'un
 * client en une fois (un chèque, un virement…), réparti sur plusieurs de ses factures ; le surplus reste à son crédit
 * (acompte) et une différence en sa faveur peut être accordée en escompte.
 *
 * Ajouts seulement :
 *  - tables encaissements et encaissement_lignes ;
 *  - facture_paiements.encaissement_id : le paiement de la facture vient d'un encaissement ;
 *  - module de droits « encaissements », réglé pour chaque groupe comme l'enregistrement des paiements de factures,
 *    avec son action « annuler ».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('encaissements')) {
            Schema::create('encaissements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('numero', 30);
                $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
                $table->date('date_encaissement');
                // especes, cheque, virement, carte, autre
                $table->string('mode', 20);
                $table->string('reference', 100)->nullable();
                // Vide : devise de l'entreprise.
                $table->string('devise', 10)->nullable();
                $table->decimal('taux_change', 14, 6)->default(1);
                // Argent reçu ; réparti sur les factures (escompte compris) ; escompte accordé ; surplus laissé en acompte.
                $table->decimal('montant', 14, 2);
                $table->decimal('montant_applique', 14, 2)->default(0);
                $table->decimal('escompte', 14, 2)->default(0);
                $table->decimal('surplus', 14, 2)->default(0);
                $table->foreignId('acompte_id')->nullable()->constrained('acomptes')->nullOnDelete();
                $table->foreignId('caisse_session_id')->nullable()->constrained('caisse_sessions')->nullOnDelete();
                $table->text('notes')->nullable();
                // valide, annule
                $table->string('statut', 20)->default('valide');
                $table->foreignId('enregistre_par')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamp('annule_le')->nullable();
                $table->foreignId('annule_par')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->string('motif_annulation', 255)->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'numero']);
                $table->index(['tenant_id', 'client_id']);
                $table->index(['tenant_id', 'date_encaissement']);
            });
        }

        if (! Schema::hasTable('encaissement_lignes')) {
            Schema::create('encaissement_lignes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('encaissement_id')->constrained('encaissements')->cascadeOnDelete();
                $table->foreignId('facture_id')->constrained('factures');
                // Montant porté sur la facture : argent et escompte.
                $table->decimal('montant', 14, 2);
                $table->decimal('escompte', 14, 2)->default(0);
                // Paiement créé sur la facture (vide une fois l'encaissement annulé).
                $table->foreignId('facture_paiement_id')->nullable()->constrained('facture_paiements')->nullOnDelete();
                $table->timestamps();
            });
        }

        Schema::table('facture_paiements', function (Blueprint $table) {
            if (! Schema::hasColumn('facture_paiements', 'encaissement_id')) {
                $table->foreignId('encaissement_id')->nullable()->constrained('encaissements')->nullOnDelete();
            }
        });

        $this->reglerDroits();
    }

    private function reglerDroits(): void
    {
        // Rejouable : droits du module pour chaque groupe qui n'en a pas encore (rôles système et groupes des
        // entreprises ; les anciens rôles personnalisés sans entreprise restent tels quels).
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        if (! Schema::hasColumns('role_permissions', ['role_id', 'module']) || ! Schema::hasTable('role_actions')) {
            return;
        }
        // Voir comme les factures ; saisir, corriger et annuler comme l'enregistrement d'un paiement (réglé pour le
        // groupe, sinon sa base : modifier les factures).
        $encaisser = DB::table('role_actions')->where('permission', 'factures.encaisser')->whereIn('role_id', $groupes)->pluck('accorde', 'role_id');
        foreach (DB::table('role_permissions')->where('module', 'factures')->whereIn('role_id', $groupes)->get() as $ligne) {
            $peut = (bool) ($encaisser[$ligne->role_id] ?? $ligne->can_edit);
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'module' => 'encaissements',
                'can_view' => $ligne->can_view, 'can_create' => $peut, 'can_edit' => $peut, 'can_delete' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('role_actions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'permission' => 'encaissements.annuler', 'accorde' => $peut,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_actions')->where('permission', 'encaissements.annuler')->delete();
        DB::table('role_permissions')->where('module', 'encaissements')->delete();
        Schema::table('facture_paiements', fn (Blueprint $table) => $table->dropConstrainedForeignId('encaissement_id'));
        Schema::dropIfExists('encaissement_lignes');
        Schema::dropIfExists('encaissements');
    }
};
