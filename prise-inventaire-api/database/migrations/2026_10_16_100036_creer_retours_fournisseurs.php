<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retours fournisseurs et avoirs (plan de parité fripv2, phase 3.4) : la marchandise renvoyée à un fournisseur
 * (reçue d'une de ses commandes, rapportée par un client, ou sans origine), pour un remboursement, un remplacement
 * ou le retour d'un contenant consigné. À l'expédition, ce qui sort du stock quitte son secteur ; à la clôture,
 * le crédit obtenu du fournisseur (avoir) ou la marchandise de remplacement reçue est noté.
 *
 * Ajouts seulement : tables retours_fournisseurs et retour_fournisseur_lignes. Droits du nouveau module
 * « retours_fournisseurs » : ceux des commandes fournisseur, pour chaque groupe (expédier un retour comme valider
 * une commande, le clôturer comme clôturer une commande).
 */
return new class extends Migration
{
    /** Action nommée des retours => action des commandes fournisseur dont elle reprend le réglage. */
    private const ACTIONS = [
        'retours_fournisseurs.expedier' => 'commandes_fournisseur.valider',
        'retours_fournisseurs.cloturer' => 'commandes_fournisseur.cloturer',
    ];

    public function up(): void
    {
        // Rejouable, comme 100029 : les tables déjà créées ne le sont pas de nouveau ; les droits se complètent.
        if (! Schema::hasTable('retours_fournisseurs')) {
            $this->creerTables();
        }
        $this->reglerDroits();
    }

    private function creerTables(): void
    {
        Schema::create('retours_fournisseurs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // Attribué à l'expédition (numérotation continue : un brouillon supprimé ne laisse pas de trou).
            $table->string('numero', 30)->nullable();
            $table->foreignId('fournisseur_id')->constrained('fournisseurs');
            // Origine facultative : commande fournisseur reçue, ou retour d'un client.
            $table->unsignedBigInteger('commande_fournisseur_id')->nullable()->index();
            $table->unsignedBigInteger('retour_client_id')->nullable()->index();
            // remboursement (crédit attendu), remplacement (marchandise attendue), consigne (contenant rendu)
            $table->string('type', 20);
            // brouillon, expedie, clos
            $table->string('statut', 20)->default('brouillon');
            $table->date('date_retour');
            $table->date('date_expedition')->nullable();
            $table->date('date_cloture')->nullable();
            // Numéro d'autorisation de retour donné par le fournisseur (RMA), à reporter sur le colis.
            $table->string('numero_rma', 50)->nullable();
            // Devise des montants (null : celle de l'entreprise) et valeur d'une unité dans celle de l'entreprise.
            $table->string('devise', 3)->nullable();
            $table->decimal('taux_change', 18, 8)->default(1);
            // Valeur des lignes (crédit attendu d'un remboursement), puis crédit obtenu et référence de l'avoir.
            $table->decimal('montant', 12, 2)->default(0);
            $table->decimal('montant_credite', 12, 2)->nullable();
            $table->string('reference_avoir', 50)->nullable();
            // Secteur où la marchandise de remplacement est entrée à la clôture.
            $table->unsignedBigInteger('secteur_remplacement_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('cree_par')->nullable();
            $table->unsignedBigInteger('expedie_par')->nullable();
            $table->unsignedBigInteger('clos_par')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'numero']);
            $table->index(['tenant_id', 'statut']);
            $table->index(['tenant_id', 'fournisseur_id']);
        });

        Schema::create('retour_fournisseur_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retour_fournisseur_id')->constrained('retours_fournisseurs')->cascadeOnDelete();
            // Ligne de la commande reçue, ou ligne du retour client d'origine.
            $table->unsignedBigInteger('com_four_ligne_id')->nullable()->index();
            $table->unsignedBigInteger('retour_client_ligne_id')->nullable()->index();
            $table->unsignedBigInteger('produit_id')->index();
            $table->unsignedInteger('quantite');
            // Prix unitaire que le fournisseur crédite (celui de la commande par défaut).
            $table->decimal('prix_unitaire', 12, 2)->default(0);
            // Raison du référentiel, de type « retour_fournisseur ».
            $table->unsignedBigInteger('raison_id')->nullable();
            // Secteur d'où la marchandise sort à l'expédition ; vide : elle n'est pas en stock (rebut, quarantaine).
            $table->unsignedBigInteger('secteur_id')->nullable();
            // Sortie créée à l'expédition, arrivage de la marchandise de remplacement à la clôture.
            $table->unsignedBigInteger('mouvement_id')->nullable();
            $table->unsignedBigInteger('mouvement_remplacement_id')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });
    }

    private function reglerDroits(): void
    {
        // Droits du module « retours_fournisseurs » : ceux des commandes fournisseur, pour chaque groupe (rôles
        // système et groupes des entreprises ; les anciens rôles personnalisés sans entreprise restent tels quels).
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        foreach (DB::table('role_permissions')->where('module', 'commandes_fournisseur')->whereIn('role_id', $groupes)->get() as $ligne) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'module' => 'retours_fournisseurs',
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
        DB::table('role_permissions')->where('module', 'retours_fournisseurs')->delete();
        DB::table('role_actions')->whereIn('permission', array_keys(self::ACTIONS))->delete();
        DB::table('admin_user_permissions')->whereIn('permission', array_keys(self::ACTIONS))->delete();
        Schema::dropIfExists('retour_fournisseur_lignes');
        Schema::dropIfExists('retours_fournisseurs');
    }
};
