<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Abonnements clients (plan de parité fripv2, phase 1.8 : « Facturation des abonnements ») : un produit facturé à un
 * client à intervalle régulier ; ses échéances, calculées d'avance, deviennent de vraies factures.
 *
 * Ajouts seulement : tables abonnements_clients et abonnement_echeances ; facture_lignes.description (texte ajouté
 * sous la désignation du produit, par exemple la période facturée). Droits du nouveau module « abonnements_clients » :
 * ceux des factures ; facturer comme créer une facture.
 */
return new class extends Migration
{
    /** Action nommée => [module, colonne] dont elle reprend le réglage, pour chaque groupe. */
    private const ACTIONS = [
        'abonnements_clients.facturer' => ['factures', 'can_create'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('abonnements_clients')) {
            Schema::create('abonnements_clients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('client_id')->constrained('clients');
                $table->unsignedBigInteger('produit_id')->index();
                // Texte ajouté sous le produit sur la facture ; variables de la période facturée.
                $table->string('description', 500)->nullable();
                $table->unsignedInteger('quantite')->default(1);
                // Prix figé (dans la devise du client), sinon le tarif du client à la date de l'échéance.
                $table->boolean('prix_fixe')->default(false);
                $table->decimal('prix_unitaire', 12, 2)->nullable();
                // mensuelle, trimestrielle, semestrielle, annuelle
                $table->string('frequence', 20)->default('mensuelle');
                // Jour (1 à 28) et, au-delà du mois, mois de facturation.
                $table->unsignedTinyInteger('jour_facturation')->default(1);
                $table->unsignedTinyInteger('mois_facturation')->nullable();
                // Début de la période couverte (jour, et mois au-delà du mois) ; période précédente : facturer après coup.
                $table->unsignedTinyInteger('jour_debut_periode')->default(1);
                $table->unsignedTinyInteger('mois_debut_periode')->nullable();
                $table->boolean('periode_precedente')->default(false);
                $table->date('date_debut');
                $table->date('date_fin');
                // Une facture qui contient cet abonnement reste en brouillon.
                $table->boolean('brouillon')->default(false);
                $table->string('notes', 500)->nullable();
                $table->unsignedBigInteger('cree_par')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'client_id']);
            });
        }
        if (! Schema::hasTable('abonnement_echeances')) {
            Schema::create('abonnement_echeances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('abonnement_id')->constrained('abonnements_clients')->cascadeOnDelete();
                $table->date('date_prevue');
                $table->date('periode_debut');
                $table->date('periode_fin');
                // Facture de l'échéance ; tant qu'il n'y en a pas, l'échéance est à facturer.
                $table->foreignId('facture_id')->nullable()->constrained('factures')->nullOnDelete();
                $table->timestamps();

                $table->unique(['abonnement_id', 'date_prevue']);
                $table->index(['tenant_id', 'date_prevue']);
            });
        }
        if (! Schema::hasColumn('facture_lignes', 'description')) {
            Schema::table('facture_lignes', function (Blueprint $table) {
                $table->string('description', 500)->nullable();
            });
        }
        $this->reglerDroits();
    }

    private function reglerDroits(): void
    {
        // Rejouable : droits du module pour chaque groupe qui n'en a pas encore (rôles système et groupes des
        // entreprises ; les anciens rôles personnalisés sans entreprise restent tels quels).
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        foreach (DB::table('role_permissions')->where('module', 'factures')->whereIn('role_id', $groupes)->get() as $ligne) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'module' => 'abonnements_clients',
                'can_view' => $ligne->can_view, 'can_create' => $ligne->can_create,
                'can_edit' => $ligne->can_edit, 'can_delete' => $ligne->can_delete,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (self::ACTIONS as $action => [$module, $colonne]) {
            foreach (DB::table('role_permissions')->where('module', $module)->whereIn('role_id', $groupes)->get() as $ligne) {
                DB::table('role_actions')->insertOrIgnore([
                    'role_id' => $ligne->role_id, 'permission' => $action, 'accorde' => (bool) $ligne->{$colonne},
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('module', 'abonnements_clients')->delete();
        DB::table('role_actions')->whereIn('permission', array_keys(self::ACTIONS))->delete();
        if (Schema::hasColumn('facture_lignes', 'description')) {
            Schema::table('facture_lignes', fn (Blueprint $table) => $table->dropColumn('description'));
        }
        Schema::dropIfExists('abonnement_echeances');
        Schema::dropIfExists('abonnements_clients');
    }
};
