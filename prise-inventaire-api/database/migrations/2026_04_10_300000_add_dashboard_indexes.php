<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index composites pour les requêtes du dashboard.
 *
 * Pattern : (tenant_id, created_at) et (tenant_id, statut, created_at)
 * pour chaque table agrégée par le dashboard.
 */
return new class extends Migration
{
    /** Vérifie si un index existe via information_schema (compatible Laravel 11+). */
    private function indexExists(string $table, string $indexName): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }

    public function up(): void
    {
        // -- devis ---------------------------------------------------------------
        if (Schema::hasTable('devis')) {
            Schema::table('devis', function (Blueprint $table) {
                if (! $this->indexExists('devis', 'devis_tenant_created_at_index')) {
                    $table->index(['tenant_id', 'created_at'], 'devis_tenant_created_at_index');
                }
                if (! $this->indexExists('devis', 'devis_tenant_statut_index')) {
                    $table->index(['tenant_id', 'statut'], 'devis_tenant_statut_index');
                }
            });
        }

        // -- com_client_entete ---------------------------------------------------
        if (Schema::hasTable('com_client_entete')) {
            Schema::table('com_client_entete', function (Blueprint $table) {
                if (! $this->indexExists('com_client_entete', 'cce_tenant_created_at_index')) {
                    $table->index(['tenant_id', 'created_at'], 'cce_tenant_created_at_index');
                }
                if (! $this->indexExists('com_client_entete', 'cce_tenant_statut_index')) {
                    $table->index(['tenant_id', 'statut'], 'cce_tenant_statut_index');
                }
            });
        }

        // -- factures ------------------------------------------------------------
        if (Schema::hasTable('factures')) {
            Schema::table('factures', function (Blueprint $table) {
                if (! $this->indexExists('factures', 'factures_tenant_created_at_index')) {
                    $table->index(['tenant_id', 'created_at'], 'factures_tenant_created_at_index');
                }
                if (! $this->indexExists('factures', 'factures_tenant_statut_index')) {
                    $table->index(['tenant_id', 'statut'], 'factures_tenant_statut_index');
                }
                if (! $this->indexExists('factures', 'factures_tenant_echeance_index')) {
                    $table->index(['tenant_id', 'statut', 'date_echeance'], 'factures_tenant_echeance_index');
                }
            });
        }

        // -- bons_livraison ------------------------------------------------------
        if (Schema::hasTable('bons_livraison')) {
            Schema::table('bons_livraison', function (Blueprint $table) {
                if (! $this->indexExists('bons_livraison', 'bl_tenant_created_at_index')) {
                    $table->index(['tenant_id', 'created_at'], 'bl_tenant_created_at_index');
                }
                if (! $this->indexExists('bons_livraison', 'bl_tenant_statut_index')) {
                    $table->index(['tenant_id', 'statut'], 'bl_tenant_statut_index');
                }
            });
        }

        // -- tournees ------------------------------------------------------------
        if (Schema::hasTable('tournees')) {
            Schema::table('tournees', function (Blueprint $table) {
                if (! $this->indexExists('tournees', 'tournees_tenant_created_at_index')) {
                    $table->index(['tenant_id', 'created_at'], 'tournees_tenant_created_at_index');
                }
                if (! $this->indexExists('tournees', 'tournees_tenant_statut_index')) {
                    $table->index(['tenant_id', 'statut'], 'tournees_tenant_statut_index');
                }
            });
        }

        // -- com_four_entete -----------------------------------------------------
        if (Schema::hasTable('com_four_entete')) {
            Schema::table('com_four_entete', function (Blueprint $table) {
                if (! $this->indexExists('com_four_entete', 'cfe_tenant_created_at_index')) {
                    $table->index(['tenant_id', 'created_at'], 'cfe_tenant_created_at_index');
                }
                if (! $this->indexExists('com_four_entete', 'cfe_tenant_statut_index')) {
                    $table->index(['tenant_id', 'statut'], 'cfe_tenant_statut_index');
                }
            });
        }

        // -- produits ------------------------------------------------------------
        if (Schema::hasTable('produits')) {
            Schema::table('produits', function (Blueprint $table) {
                if (! $this->indexExists('produits', 'produits_tenant_deleted_at_index')) {
                    $table->index(['tenant_id', 'deleted_at'], 'produits_tenant_deleted_at_index');
                }
            });
        }

        // -- alertes_stock -------------------------------------------------------
        if (Schema::hasTable('alertes_stock')) {
            Schema::table('alertes_stock', function (Blueprint $table) {
                if (! $this->indexExists('alertes_stock', 'alertes_stock_tenant_statut_index')) {
                    $table->index(['tenant_id', 'statut'], 'alertes_stock_tenant_statut_index');
                }
            });
        }

        // -- mouvement_inventaire ------------------------------------------------
        if (Schema::hasTable('mouvement_inventaire')) {
            Schema::table('mouvement_inventaire', function (Blueprint $table) {
                if (! $this->indexExists('mouvement_inventaire', 'mi_tenant_created_at_index')) {
                    $table->index(['tenant_id', 'created_at'], 'mi_tenant_created_at_index');
                }
                if (! $this->indexExists('mouvement_inventaire', 'mi_tenant_type_index')) {
                    $table->index(['tenant_id', 'type_mouvement'], 'mi_tenant_type_index');
                }
            });
        }

        // -- facture_paiements (joint via facture.tenant_id) ---------------------
        if (Schema::hasTable('facture_paiements')) {
            Schema::table('facture_paiements', function (Blueprint $table) {
                if (! $this->indexExists('facture_paiements', 'fp_facture_created_at_index')) {
                    $table->index(['facture_id', 'created_at'], 'fp_facture_created_at_index');
                }
            });
        }
    }

    public function down(): void
    {
        $map = [
            'devis' => ['devis_tenant_created_at_index', 'devis_tenant_statut_index'],
            'com_client_entete' => ['cce_tenant_created_at_index', 'cce_tenant_statut_index'],
            'factures' => ['factures_tenant_created_at_index', 'factures_tenant_statut_index', 'factures_tenant_echeance_index'],
            'bons_livraison' => ['bl_tenant_created_at_index', 'bl_tenant_statut_index'],
            'tournees' => ['tournees_tenant_created_at_index', 'tournees_tenant_statut_index'],
            'com_four_entete' => ['cfe_tenant_created_at_index', 'cfe_tenant_statut_index'],
            'produits' => ['produits_tenant_deleted_at_index'],
            'alertes_stock' => ['alertes_stock_tenant_statut_index'],
            'mouvement_inventaire' => ['mi_tenant_created_at_index', 'mi_tenant_type_index'],
            'facture_paiements' => ['fp_facture_created_at_index'],
        ];

        foreach ($map as $tableName => $indexNames) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $indexNames) {
                foreach ($indexNames as $indexName) {
                    if ($this->indexExists($tableName, $indexName)) {
                        $table->dropIndex($indexName);
                    }
                }
            });
        }
    }
};
