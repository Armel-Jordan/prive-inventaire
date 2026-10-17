<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contrats de location (plan de parité fripv2, phase 1.8 : « Contrats de location », « Types de location ») : des
 * produits loués à un client sur une période, avec un prix de contrat facturé une fois (contenu, caution, mise en
 * service) et un loyer facturé à chaque période ; retours, renouvellement manuel ou automatique.
 *
 * Ajouts seulement : référentiel types_location ; tables contrats_location et contrat_location_lignes ;
 * factures.contrat_location_id (factures d'un contrat). Droits du nouveau module « contrats_location » : ceux des
 * factures ; facturer et renouveler comme créer une facture.
 */
return new class extends Migration
{
    /** Action nommée => [module, colonne] dont elle reprend le réglage, pour chaque groupe. */
    private const ACTIONS = [
        'contrats_location.facturer' => ['factures', 'can_create'],
        'contrats_location.renouveler' => ['factures', 'can_create'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('types_location')) {
            Schema::create('types_location', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('code', 20);
                $table->string('nom', 100);
                // Modèle de contrat : durée, rythme des loyers, jour de facturation, loyer à l'avance ou après coup.
                $table->unsignedSmallInteger('duree_mois')->default(12);
                $table->string('frequence', 20)->default('mensuelle');
                $table->unsignedTinyInteger('jour_facturation')->default(1);
                $table->boolean('a_echoir')->default(true);
                $table->boolean('actif')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });
        }
        if (! Schema::hasTable('contrats_location')) {
            Schema::create('contrats_location', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('numero', 30);
                $table->foreignId('client_id')->constrained('clients');
                $table->unsignedBigInteger('type_location_id')->nullable()->index();
                $table->date('date_debut');
                $table->date('date_fin');
                // Loyers : rythme, jour, à l'avance (à échoir) ou après coup ; prochaine et dernière facturation.
                $table->string('frequence', 20)->default('mensuelle');
                $table->unsignedTinyInteger('jour_facturation')->default(1);
                $table->boolean('a_echoir')->default(true);
                $table->date('prochaine_facturation')->nullable();
                $table->date('derniere_facturation')->nullable();
                // Date de la facture des prix de contrat.
                $table->date('date_facturation_contrat')->nullable();
                $table->boolean('actif')->default(true);
                // Renouvellement automatique à la fin, avec hausse des prix (%).
                $table->boolean('renouvellement_auto')->default(false);
                $table->decimal('taux_contrat', 6, 2)->default(0);
                $table->decimal('taux_location', 6, 2)->default(0);
                $table->unsignedBigInteger('contrat_precedent_id')->nullable()->index();
                $table->unsignedBigInteger('renouvele_par_id')->nullable()->index();
                $table->string('notes', 1000)->nullable();
                $table->unsignedBigInteger('cree_par')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'numero']);
                $table->index(['tenant_id', 'actif', 'prochaine_facturation'], 'contrats_location_a_facturer_index');
            });
        }
        if (! Schema::hasTable('contrat_location_lignes')) {
            Schema::create('contrat_location_lignes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('contrat_id')->constrained('contrats_location')->cascadeOnDelete();
                $table->unsignedBigInteger('produit_id')->index();
                $table->unsignedInteger('quantite');
                // Unités sans loyer (comprises dans un forfait) ; unités rendues (plus de loyer ensuite).
                $table->unsignedInteger('quantite_exemptee')->default(0);
                $table->unsignedInteger('quantite_retournee')->default(0);
                $table->date('date_retour')->nullable();
                // Prix de contrat (facturé une fois) et loyer par unité et par période, dans la devise du client.
                $table->decimal('prix_contrat', 12, 2)->default(0);
                $table->decimal('prix_location', 12, 2)->default(0);
                // Facture du prix de contrat ; tant qu'il n'y en a pas, la ligne est à facturer.
                $table->unsignedBigInteger('facture_id')->nullable()->index();
                $table->timestamps();
            });
        }
        if (! Schema::hasColumn('factures', 'contrat_location_id')) {
            Schema::table('factures', function (Blueprint $table) {
                $table->unsignedBigInteger('contrat_location_id')->nullable()->index();
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
                'role_id' => $ligne->role_id, 'module' => 'contrats_location',
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
        DB::table('role_permissions')->where('module', 'contrats_location')->delete();
        DB::table('role_actions')->whereIn('permission', array_keys(self::ACTIONS))->delete();
        if (Schema::hasColumn('factures', 'contrat_location_id')) {
            Schema::table('factures', function (Blueprint $table) {
                $table->dropIndex(['contrat_location_id']);
                $table->dropColumn('contrat_location_id');
            });
        }
        Schema::dropIfExists('contrat_location_lignes');
        Schema::dropIfExists('contrats_location');
        Schema::dropIfExists('types_location');
    }
};
