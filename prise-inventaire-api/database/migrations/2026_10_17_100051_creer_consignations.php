<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consignation chez le client (plan de parité fripv2, phase 1.9 : « Relevé de consignation », « Consignations chez
 * les clients ») : de la marchandise déposée chez un client reste à l'entreprise jusqu'à ce qu'il la consomme (elle
 * est alors facturée) ou la rende.
 *
 * Ajouts seulement : tables consignations (bons de dépôt), consignation_lignes et consignation_mouvements (dépôts,
 * retours et consommations : le solde de chaque client se calcule depuis ces mouvements). Droits du nouveau module
 * « consignations » : ceux des factures ; facturer la consommation comme créer une facture.
 */
return new class extends Migration
{
    /** Action nommée => [module, colonne] dont elle reprend le réglage, pour chaque groupe. */
    private const ACTIONS = [
        'consignations.facturer' => ['factures', 'can_create'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('consignations')) {
            Schema::create('consignations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('numero', 30);
                $table->foreignId('client_id')->constrained('clients');
                $table->date('date_depot');
                $table->string('notes', 1000)->nullable();
                $table->unsignedBigInteger('cree_par')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'numero']);
            });
        }
        if (! Schema::hasTable('consignation_lignes')) {
            Schema::create('consignation_lignes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('consignation_id')->constrained('consignations')->cascadeOnDelete();
                $table->unsignedBigInteger('produit_id')->index();
                $table->unsignedInteger('quantite');
                // Coût unitaire au dépôt (coût moyen d'achat) : la valeur de l'actif placé chez le client.
                $table->decimal('cout_unitaire', 14, 4)->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('consignation_mouvements')) {
            Schema::create('consignation_mouvements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('client_id')->constrained('clients');
                $table->unsignedBigInteger('produit_id');
                // depot (+), retour (−), consommation (− , facturée).
                $table->string('type', 15);
                $table->integer('quantite');
                $table->decimal('cout_unitaire', 14, 4)->nullable();
                $table->dateTime('date_mouvement');
                $table->unsignedBigInteger('consignation_id')->nullable()->index();
                $table->unsignedBigInteger('facture_id')->nullable()->index();
                // Lieu où revient la marchandise reprise.
                $table->string('lieu_retour', 100)->nullable();
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('cree_par')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'client_id', 'produit_id'], 'consignation_mouvements_solde_index');
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
                'role_id' => $ligne->role_id, 'module' => 'consignations',
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
        DB::table('role_permissions')->where('module', 'consignations')->delete();
        DB::table('role_actions')->whereIn('permission', array_keys(self::ACTIONS))->delete();
        Schema::dropIfExists('consignation_mouvements');
        Schema::dropIfExists('consignation_lignes');
        Schema::dropIfExists('consignations');
    }
};
