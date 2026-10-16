<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notes de crédit (avoirs) sur les factures (plan de parité fripv2, phase 1.5) : créditer tout ou partie d'une
 * facture émise (retour, erreur, geste commercial). À son émission, la note reçoit son numéro et s'impute sur ce
 * qui reste dû de la facture ; le crédit restant (facture déjà payée) se rembourse au client ou s'impute sur une
 * autre de ses factures.
 *
 * Ajouts seulement : tables avoirs, avoir_lignes, avoir_imputations, avoir_remboursements ;
 * factures.montant_credite ; statut « creditee » (facture soldée par des notes de crédit, sans paiement).
 * Droits du nouveau module « avoirs » : ceux des factures, pour chaque groupe (émettre une note de crédit comme
 * émettre une facture, régler un crédit comme enregistrer un paiement).
 */
return new class extends Migration
{
    private const STATUTS_FACTURE = ['brouillon', 'emise', 'partiellement_payee', 'payee', 'annulee'];

    /** Action nommée des notes de crédit => action des factures dont elle reprend le réglage. */
    private const ACTIONS = ['avoirs.emettre' => 'factures.emettre', 'avoirs.regler' => 'factures.encaisser'];

    public function up(): void
    {
        // Rejouable, comme 100029 : les tables déjà créées ne le sont pas de nouveau ; les droits se complètent.
        if (! Schema::hasTable('avoirs')) {
            $this->creerTables();
        }
        $this->reglerDroits();
    }

    private function creerTables(): void
    {
        Schema::create('avoirs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // Attribué à l'émission (numérotation continue : un brouillon supprimé ne laisse pas de trou).
            $table->string('numero', 30)->nullable();
            $table->foreignId('facture_id')->constrained('factures');
            $table->foreignId('client_id')->constrained('clients');
            $table->date('date_avoir');
            // brouillon, emis (crédit encore disponible), solde (entièrement imputé ou remboursé)
            $table->string('statut', 20)->default('brouillon');
            $table->string('motif', 255)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('montant_ht', 12, 2)->default(0);
            $table->decimal('montant_tva', 12, 2)->default(0);
            $table->decimal('montant_ttc', 12, 2)->default(0);
            // Totaux des imputations (sur des factures) et des remboursements : le reste est le crédit disponible.
            $table->decimal('montant_impute', 12, 2)->default(0);
            $table->decimal('montant_rembourse', 12, 2)->default(0);
            $table->dateTime('emis_le')->nullable();
            $table->unsignedBigInteger('emis_par')->nullable();
            $table->unsignedBigInteger('cree_par')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'numero']);
            $table->index(['tenant_id', 'statut']);
        });

        Schema::create('avoir_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('avoir_id')->constrained('avoirs')->cascadeOnDelete();
            // Ligne de facture créditée.
            $table->unsignedBigInteger('facture_ligne_id')->index();
            $table->unsignedBigInteger('produit_id')->nullable();
            $table->integer('quantite');
            $table->decimal('prix_unitaire_ht', 12, 2);
            $table->decimal('taux_tva', 5, 2)->default(0);
            $table->unsignedBigInteger('groupe_taxe_id')->nullable();
            $table->decimal('remise_ligne', 5, 2)->default(0);
            $table->decimal('montant_ht', 12, 2)->default(0);
            $table->decimal('montant_tva', 12, 2)->default(0);
            $table->decimal('montant_ttc', 12, 2)->default(0);
            $table->timestamps();
        });

        // Part d'une note de crédit imputée sur une facture du client (la sienne à l'émission, une autre ensuite).
        Schema::create('avoir_imputations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('avoir_id')->constrained('avoirs')->cascadeOnDelete();
            $table->foreignId('facture_id')->constrained('factures');
            $table->date('date_imputation');
            $table->decimal('montant', 12, 2);
            $table->unsignedBigInteger('enregistre_par')->nullable();
            $table->timestamps();
        });

        Schema::create('avoir_remboursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('avoir_id')->constrained('avoirs')->cascadeOnDelete();
            $table->date('date_remboursement');
            $table->decimal('montant', 12, 2);
            $table->string('mode', 30)->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('enregistre_par')->nullable();
            $table->timestamps();
        });

        Schema::table('factures', function (Blueprint $table) {
            $table->decimal('montant_credite', 12, 2)->default(0)->after('montant_paye');
        });
        Schema::table('factures', function (Blueprint $table) {
            $table->enum('statut', [...self::STATUTS_FACTURE, 'creditee'])->default('brouillon')->change();
        });
    }

    private function reglerDroits(): void
    {
        // Droits du module « avoirs » : ceux des factures, pour chaque groupe (rôles système et groupes des
        // entreprises ; les anciens rôles personnalisés sans entreprise, masqués à toutes, restent tels quels).
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        foreach (DB::table('role_permissions')->where('module', 'factures')->whereIn('role_id', $groupes)->get() as $ligne) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'module' => 'avoirs',
                'can_view' => $ligne->can_view, 'can_create' => $ligne->can_create,
                'can_edit' => $ligne->can_edit, 'can_delete' => $ligne->can_delete,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (self::ACTIONS as $action => $modele) {
            foreach (DB::table('role_actions')->where('permission', $modele)->whereIn('role_id', $groupes)->get() as $reglee) {
                DB::table('role_actions')->insertOrIgnore([
                    'role_id' => $reglee->role_id, 'permission' => $action, 'accorde' => $reglee->accorde,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach (DB::table('admin_user_permissions')->where('permission', $modele)->get() as $exception) {
                DB::table('admin_user_permissions')->insertOrIgnore([
                    'admin_user_id' => $exception->admin_user_id, 'permission' => $action, 'accorde' => $exception->accorde,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('module', 'avoirs')->delete();
        DB::table('role_actions')->whereIn('permission', array_keys(self::ACTIONS))->delete();
        DB::table('admin_user_permissions')->whereIn('permission', array_keys(self::ACTIONS))->delete();
        DB::table('factures')->where('statut', 'creditee')->update(['statut' => 'payee']);
        Schema::table('factures', function (Blueprint $table) {
            $table->enum('statut', self::STATUTS_FACTURE)->default('brouillon')->change();
        });
        Schema::table('factures', function (Blueprint $table) {
            $table->dropColumn('montant_credite');
        });
        Schema::dropIfExists('avoir_remboursements');
        Schema::dropIfExists('avoir_imputations');
        Schema::dropIfExists('avoir_lignes');
        Schema::dropIfExists('avoirs');
    }
};
