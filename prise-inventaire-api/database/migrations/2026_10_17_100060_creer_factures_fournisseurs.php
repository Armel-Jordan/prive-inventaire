<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Factures fournisseurs (plan de parité fripv2, phase 3.5 : « Compte à payer ») : la facture reçue d'un fournisseur,
 * saisie à la main ou depuis ses réceptions (qui passent alors « facturées »), rapprochée de la marchandise reçue,
 * avec ses taxes, son échéance et ses paiements.
 *
 * Ajouts seulement :
 *  - tables factures_fournisseurs, facture_fournisseur_lignes, facture_fournisseur_paiements ;
 *  - receptions.facture_fournisseur_id : la facture qui règle la réception ;
 *  - module de droits « factures_fournisseurs », réglé pour chaque groupe comme les commandes fournisseur ; ses actions
 *    « payer » (comme l'encaissement des factures) et « annuler » (comme la suppression des commandes fournisseur).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('factures_fournisseurs')) {
            Schema::create('factures_fournisseurs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('numero', 30);
                $table->foreignId('fournisseur_id')->constrained('fournisseurs')->restrictOnDelete();
                // Numéro de la facture chez le fournisseur, tel qu'il figure sur le document reçu.
                $table->string('numero_fournisseur', 60)->nullable();
                $table->date('date_facture');
                $table->date('date_echeance');
                $table->foreignId('condition_paiement_id')->nullable()->constrained('conditions_paiement')->nullOnDelete();
                // Vide : devise de l'entreprise.
                $table->string('devise', 10)->nullable();
                $table->decimal('taux_change', 14, 6)->default(1);
                $table->decimal('montant_ht', 14, 2)->default(0);
                $table->decimal('montant_tva', 14, 2)->default(0);
                $table->decimal('montant_ttc', 14, 2)->default(0);
                $table->decimal('montant_paye', 14, 2)->default(0);
                // enregistree, annulee (l'état de paiement se calcule : impayée, partielle, payée, en retard).
                $table->string('statut', 20)->default('enregistree');
                $table->text('notes')->nullable();
                $table->foreignId('cree_par')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamp('annulee_le')->nullable();
                $table->foreignId('annulee_par')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->string('motif_annulation', 255)->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'numero']);
                $table->index(['tenant_id', 'fournisseur_id']);
                $table->index(['tenant_id', 'date_echeance']);
            });
        }

        if (! Schema::hasTable('facture_fournisseur_lignes')) {
            Schema::create('facture_fournisseur_lignes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('facture_fournisseur_id')->constrained('factures_fournisseurs')->cascadeOnDelete();
                $table->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
                $table->string('description', 255);
                $table->decimal('quantite', 12, 3);
                $table->decimal('prix_unitaire', 14, 4);
                $table->decimal('montant_ht', 14, 2);
                $table->foreignId('groupe_taxe_id')->nullable()->constrained('groupes_taxes')->nullOnDelete();
                $table->decimal('taux_tva', 7, 3)->default(0);
                $table->decimal('montant_tva', 14, 2)->default(0);
                $table->decimal('montant_ttc', 14, 2);
                // Ligne de réception facturée : sert au rapprochement avec la marchandise reçue.
                $table->foreignId('reception_ligne_id')->nullable()->constrained('reception_arrivages_ligne')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('facture_fournisseur_paiements')) {
            Schema::create('facture_fournisseur_paiements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('facture_fournisseur_id')->constrained('factures_fournisseurs')->cascadeOnDelete();
                $table->date('date_paiement');
                $table->decimal('montant', 14, 2);
                // especes, cheque, virement, carte, autre
                $table->string('mode', 20);
                $table->string('reference', 60)->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('enregistre_par')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamps();
            });
        }

        Schema::table('receptions', function (Blueprint $table) {
            if (! Schema::hasColumn('receptions', 'facture_fournisseur_id')) {
                $table->foreignId('facture_fournisseur_id')->nullable()->after('statut')->constrained('factures_fournisseurs')->nullOnDelete();
            }
        });

        $this->reglerDroits();
    }

    private function reglerDroits(): void
    {
        // Rejouable : droits du module pour chaque groupe qui n'en a pas encore (rôles système et groupes des
        // entreprises ; les anciens rôles personnalisés sans entreprise restent tels quels).
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        foreach (DB::table('role_permissions')->where('module', 'commandes_fournisseur')->whereIn('role_id', $groupes)->get() as $ligne) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'module' => 'factures_fournisseurs',
                'can_view' => $ligne->can_view, 'can_create' => $ligne->can_create,
                'can_edit' => $ligne->can_edit, 'can_delete' => $ligne->can_delete,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('role_actions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'permission' => 'factures_fournisseurs.annuler', 'accorde' => (bool) $ligne->can_delete,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // Payer un fournisseur suit le droit d'enregistrer un paiement de facture : réglé pour le groupe, sinon sa base
        // (modifier les factures).
        $encaisser = DB::table('role_actions')->where('permission', 'factures.encaisser')->whereIn('role_id', $groupes)->pluck('accorde', 'role_id');
        foreach (DB::table('role_permissions')->where('module', 'factures')->whereIn('role_id', $groupes)->get() as $ligne) {
            DB::table('role_actions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'permission' => 'factures_fournisseurs.payer',
                'accorde' => (bool) ($encaisser[$ligne->role_id] ?? $ligne->can_edit),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_actions')->whereIn('permission', ['factures_fournisseurs.payer', 'factures_fournisseurs.annuler'])->delete();
        DB::table('role_permissions')->where('module', 'factures_fournisseurs')->delete();
        Schema::table('receptions', fn (Blueprint $table) => $table->dropConstrainedForeignId('facture_fournisseur_id'));
        Schema::dropIfExists('facture_fournisseur_paiements');
        Schema::dropIfExists('facture_fournisseur_lignes');
        Schema::dropIfExists('factures_fournisseurs');
    }
};
