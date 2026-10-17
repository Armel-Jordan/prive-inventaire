<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Déboursés aux fournisseurs (plan de parité fripv2, phase 4.5 : « Déboursé à un fournisseur ») : l'argent versé à un
 * fournisseur en une fois (un chèque, un virement…), réparti sur plusieurs de ses factures, avec l'escompte qu'il
 * accorde pour un paiement rapide.
 *
 * Ajouts seulement :
 *  - tables debourses et debourse_lignes ;
 *  - facture_fournisseur_paiements.escompte (escompte obtenu avec le paiement) et .debourse_id (le paiement est une
 *    part d'un déboursé) ;
 *  - parametres_comptables.compte_escomptes_obtenus_id (comptabilisation automatique) ;
 *  - module de droits « debourses », réglé pour chaque groupe comme le paiement des factures fournisseurs, avec son
 *    action « annuler ».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('debourses')) {
            Schema::create('debourses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('numero', 30);
                $table->foreignId('fournisseur_id')->constrained('fournisseurs')->restrictOnDelete();
                $table->date('date_debourse');
                // especes, cheque, virement, carte, autre
                $table->string('mode', 20);
                $table->string('reference', 100)->nullable();
                // Vide : devise de l'entreprise.
                $table->string('devise', 10)->nullable();
                $table->decimal('taux_change', 14, 6)->default(1);
                // Argent versé ; porté sur les factures (escomptes compris) ; escomptes obtenus.
                $table->decimal('montant', 14, 2);
                $table->decimal('montant_applique', 14, 2)->default(0);
                $table->decimal('escompte', 14, 2)->default(0);
                $table->text('notes')->nullable();
                // valide, annule
                $table->string('statut', 20)->default('valide');
                $table->foreignId('enregistre_par')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamp('annule_le')->nullable();
                $table->foreignId('annule_par')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->string('motif_annulation', 255)->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'numero']);
                $table->index(['tenant_id', 'fournisseur_id']);
                $table->index(['tenant_id', 'date_debourse']);
            });
        }

        if (! Schema::hasTable('debourse_lignes')) {
            Schema::create('debourse_lignes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('debourse_id')->constrained('debourses')->cascadeOnDelete();
                $table->foreignId('facture_fournisseur_id')->constrained('factures_fournisseurs');
                // Montant porté sur la facture : argent et escompte.
                $table->decimal('montant', 14, 2);
                $table->decimal('escompte', 14, 2)->default(0);
                // Paiement créé sur la facture (vide une fois le déboursé annulé).
                $table->foreignId('paiement_id')->nullable()->constrained('facture_fournisseur_paiements')->nullOnDelete();
                $table->timestamps();
            });
        }

        Schema::table('facture_fournisseur_paiements', function (Blueprint $table) {
            if (! Schema::hasColumn('facture_fournisseur_paiements', 'escompte')) {
                $table->decimal('escompte', 14, 2)->default(0)->after('montant');
            }
            if (! Schema::hasColumn('facture_fournisseur_paiements', 'debourse_id')) {
                $table->foreignId('debourse_id')->nullable()->constrained('debourses')->nullOnDelete();
            }
        });

        Schema::table('parametres_comptables', function (Blueprint $table) {
            if (! Schema::hasColumn('parametres_comptables', 'compte_escomptes_obtenus_id')) {
                $table->foreignId('compte_escomptes_obtenus_id')->nullable()->constrained('comptes_comptables')->nullOnDelete();
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
        // Voir comme les factures fournisseurs ; saisir, corriger et annuler comme le paiement d'une facture fournisseur
        // (réglé pour le groupe, sinon sa base : modifier les factures fournisseurs).
        $payer = DB::table('role_actions')->where('permission', 'factures_fournisseurs.payer')->whereIn('role_id', $groupes)->pluck('accorde', 'role_id');
        foreach (DB::table('role_permissions')->where('module', 'factures_fournisseurs')->whereIn('role_id', $groupes)->get() as $ligne) {
            $peut = (bool) ($payer[$ligne->role_id] ?? $ligne->can_edit);
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'module' => 'debourses',
                'can_view' => $ligne->can_view, 'can_create' => $peut, 'can_edit' => $peut, 'can_delete' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('role_actions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'permission' => 'debourses.annuler', 'accorde' => $peut,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_actions')->where('permission', 'debourses.annuler')->delete();
        DB::table('role_permissions')->where('module', 'debourses')->delete();
        Schema::table('parametres_comptables', fn (Blueprint $table) => $table->dropConstrainedForeignId('compte_escomptes_obtenus_id'));
        Schema::table('facture_fournisseur_paiements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('debourse_id');
            $table->dropColumn('escompte');
        });
        Schema::dropIfExists('debourse_lignes');
        Schema::dropIfExists('debourses');
    }
};
