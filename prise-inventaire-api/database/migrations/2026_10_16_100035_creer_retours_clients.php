<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retours clients (plan de parité fripv2, phase 1.7) : la marchandise rapportée par un client, avec la raison du
 * retour, est remise en stock dans un secteur (un arrivage, compté comme une entrée par les prises d'inventaire) ou
 * mise au rebut. Le retour d'une facture peut ensuite donner sa note de crédit.
 *
 * Ajouts seulement : tables retours_clients et retour_client_lignes. Droits du nouveau module « retours_clients » :
 * ceux des factures, pour chaque groupe (traiter un retour comme émettre une facture).
 */
return new class extends Migration
{
    /** Action nommée des retours => action des factures dont elle reprend le réglage. */
    private const ACTIONS = ['retours_clients.traiter' => 'factures.emettre'];

    public function up(): void
    {
        // Rejouable, comme 100029 : les tables déjà créées ne le sont pas de nouveau ; les droits se complètent.
        if (! Schema::hasTable('retours_clients')) {
            $this->creerTables();
        }
        $this->reglerDroits();
    }

    private function creerTables(): void
    {
        Schema::create('retours_clients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // Attribué au traitement (numérotation continue : un brouillon supprimé ne laisse pas de trou).
            $table->string('numero', 30)->nullable();
            $table->foreignId('client_id')->constrained('clients');
            // Facture d'origine, facultative (vente faite hors de l'application, prêt, échantillon…).
            $table->unsignedBigInteger('facture_id')->nullable()->index();
            $table->date('date_retour');
            // brouillon, traite (marchandise remise en stock ou mise au rebut)
            $table->string('statut', 20)->default('brouillon');
            $table->text('notes')->nullable();
            // Note de crédit créée depuis le retour.
            $table->unsignedBigInteger('avoir_id')->nullable()->index();
            $table->dateTime('traite_le')->nullable();
            $table->unsignedBigInteger('traite_par')->nullable();
            $table->unsignedBigInteger('cree_par')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'numero']);
            $table->index(['tenant_id', 'statut']);
            $table->index(['tenant_id', 'client_id']);
        });

        Schema::create('retour_client_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retour_client_id')->constrained('retours_clients')->cascadeOnDelete();
            // Ligne de la facture d'origine (retour d'une facture).
            $table->unsignedBigInteger('facture_ligne_id')->nullable()->index();
            $table->unsignedBigInteger('produit_id')->index();
            $table->unsignedInteger('quantite');
            // Raison du référentiel, de type « retour_client ».
            $table->unsignedBigInteger('raison_id')->nullable();
            // remise_en_stock, rebut
            $table->string('traitement', 20);
            // Secteur où la marchandise est remise en stock.
            $table->unsignedBigInteger('secteur_id')->nullable();
            // Arrivage créé par la remise en stock (mouvement_relocalisation).
            $table->unsignedBigInteger('mouvement_id')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });
    }

    private function reglerDroits(): void
    {
        // Droits du module « retours_clients » : ceux des factures, pour chaque groupe (rôles système et groupes
        // des entreprises ; les anciens rôles personnalisés sans entreprise, masqués à toutes, restent tels quels).
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        foreach (DB::table('role_permissions')->where('module', 'factures')->whereIn('role_id', $groupes)->get() as $ligne) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'module' => 'retours_clients',
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
        DB::table('role_permissions')->where('module', 'retours_clients')->delete();
        DB::table('role_actions')->whereIn('permission', array_keys(self::ACTIONS))->delete();
        DB::table('admin_user_permissions')->whereIn('permission', array_keys(self::ACTIONS))->delete();
        Schema::dropIfExists('retour_client_lignes');
        Schema::dropIfExists('retours_clients');
    }
};
