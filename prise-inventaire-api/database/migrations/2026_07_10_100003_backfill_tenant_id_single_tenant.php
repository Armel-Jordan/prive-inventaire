<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill des lignes tenant_id IS NULL vers l'unique tenant existant.
 *
 * Nécessaire AVANT d'activer le Global Scope tenant : sinon les lignes historiques
 * à tenant_id NULL deviendraient invisibles en lecture.
 *
 * Sécurité : le backfill n'est appliqué QUE s'il existe exactement UN tenant
 * (cas d'un déploiement parti mono-tenant). En présence de plusieurs tenants,
 * l'affectation ne peut pas être devinée -> no-op, rattachement manuel requis.
 */
return new class extends Migration
{
    /** Tables métier scopées par tenant (hors admin_users, déjà NOT NULL). */
    private array $tables = [
        'approbations', 'audit_logs', 'bons_livraison', 'camions', 'clients',
        'com_client_entete', 'com_four_entete', 'configurations', 'devis', 'factures',
        'mouvement_inventaire', 'mouvement_relocalisation', 'produit_localisations',
        'inventaire_scan', 'tenant_parametres', 'tenant_taxes', 'tournees',
        'transferts_planifies', 'zones_preparation', 'alertes_stock',
        'parametres_approbation', 'produits', 'employes', 'secteurs',
        'notifications', 'mouvements_inventaire', 'fournisseurs',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('tenants')) {
            return;
        }

        $tenantIds = DB::table('tenants')->pluck('id');
        if ($tenantIds->count() !== 1) {
            // 0 ou plusieurs tenants : backfill automatique impossible sans ambiguïté.
            return;
        }

        $tenantId = $tenantIds->first();

        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'tenant_id')) {
                DB::table($table)->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
            }
        }

        // Rôles custom (non-système) : rattachés au tenant. Les rôles système restent NULL (partagés).
        if (Schema::hasTable('roles_custom') && Schema::hasColumn('roles_custom', 'tenant_id')) {
            DB::table('roles_custom')
                ->whereNull('tenant_id')
                ->where('is_system', false)
                ->update(['tenant_id' => $tenantId]);
        }
    }

    public function down(): void
    {
        // Irréversible : on ne peut pas distinguer les lignes backfillées des autres.
    }
};
