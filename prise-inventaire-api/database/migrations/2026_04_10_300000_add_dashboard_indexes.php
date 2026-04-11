<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index composites pour les requêtes du dashboard.
 *
 * Pattern : (tenant_id, created_at) et (tenant_id, statut, created_at)
 * pour chaque table agrégée par le dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        // -- devis ---------------------------------------------------------------
        if (Schema::hasTable('devis')) {
            Schema::table('devis', function (Blueprint $table) {
                // Évite les doublons si l'index existe déjà
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $indexes = array_keys($sm->listTableIndexes('devis'));

                if (! in_array('devis_tenant_created_at_index', $indexes)) {
                    $table->index(['tenant_id', 'created_at'], 'devis_tenant_created_at_index');
                }
                if (! in_array('devis_tenant_statut_index', $indexes)) {
                    $table->index(['tenant_id', 'statut'], 'devis_tenant_statut_index');
                }
            });
        }

        // -- com_client_entete ---------------------------------------------------
        if (Schema::hasTable('com_client_entete')) {
            Schema::table('com_client_entete', function (Blueprint $table) {
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $indexes = array_keys($sm->listTableIndexes('com_client_entete'));

                if (! in_array('cce_tenant_created_at_index', $indexes)) {
                    $table->index(['tenant_id', 'created_at'], 'cce_tenant_created_at_index');
                }
                if (! in_array('cce_tenant_statut_index', $indexes)) {
                    $table->index(['tenant_id', 'statut'], 'cce_tenant_statut_index');
                }
            });
        }

        // -- factures ------------------------------------------------------------
        if (Schema::hasTable('factures')) {
            Schema::table('factures', function (Blueprint $table) {
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $indexes = array_keys($sm->listTableIndexes('factures'));

                if (! in_array('factures_tenant_created_at_index', $indexes)) {
                    $table->index(['tenant_id', 'created_at'], 'factures_tenant_created_at_index');
                }
                if (! in_array('factures_tenant_statut_index', $indexes)) {
                    $table->index(['tenant_id', 'statut'], 'factures_tenant_statut_index');
                }
                if (! in_array('factures_tenant_echeance_index', $indexes)) {
                    $table->index(['tenant_id', 'statut', 'date_echeance'], 'factures_tenant_echeance_index');
                }
            });
        }

        // -- bons_livraison ------------------------------------------------------
        if (Schema::hasTable('bons_livraison')) {
            Schema::table('bons_livraison', function (Blueprint $table) {
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $indexes = array_keys($sm->listTableIndexes('bons_livraison'));

                if (! in_array('bl_tenant_created_at_index', $indexes)) {
                    $table->index(['tenant_id', 'created_at'], 'bl_tenant_created_at_index');
                }
                if (! in_array('bl_tenant_statut_index', $indexes)) {
                    $table->index(['tenant_id', 'statut'], 'bl_tenant_statut_index');
                }
            });
        }

        // -- tournees ------------------------------------------------------------
        if (Schema::hasTable('tournees')) {
            Schema::table('tournees', function (Blueprint $table) {
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $indexes = array_keys($sm->listTableIndexes('tournees'));

                if (! in_array('tournees_tenant_created_at_index', $indexes)) {
                    $table->index(['tenant_id', 'created_at'], 'tournees_tenant_created_at_index');
                }
                if (! in_array('tournees_tenant_statut_index', $indexes)) {
                    $table->index(['tenant_id', 'statut'], 'tournees_tenant_statut_index');
                }
            });
        }

        // -- com_four_entete -----------------------------------------------------
        if (Schema::hasTable('com_four_entete')) {
            Schema::table('com_four_entete', function (Blueprint $table) {
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $indexes = array_keys($sm->listTableIndexes('com_four_entete'));

                if (! in_array('cfe_tenant_created_at_index', $indexes)) {
                    $table->index(['tenant_id', 'created_at'], 'cfe_tenant_created_at_index');
                }
                if (! in_array('cfe_tenant_statut_index', $indexes)) {
                    $table->index(['tenant_id', 'statut'], 'cfe_tenant_statut_index');
                }
            });
        }

        // -- produits ------------------------------------------------------------
        if (Schema::hasTable('produits')) {
            Schema::table('produits', function (Blueprint $table) {
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $indexes = array_keys($sm->listTableIndexes('produits'));

                if (! in_array('produits_tenant_deleted_at_index', $indexes)) {
                    $table->index(['tenant_id', 'deleted_at'], 'produits_tenant_deleted_at_index');
                }
            });
        }

        // -- alertes_stock -------------------------------------------------------
        if (Schema::hasTable('alertes_stock')) {
            Schema::table('alertes_stock', function (Blueprint $table) {
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $indexes = array_keys($sm->listTableIndexes('alertes_stock'));

                if (! in_array('alertes_stock_tenant_statut_index', $indexes)) {
                    $table->index(['tenant_id', 'statut'], 'alertes_stock_tenant_statut_index');
                }
            });
        }

        // -- mouvement_inventaire ------------------------------------------------
        if (Schema::hasTable('mouvement_inventaire')) {
            Schema::table('mouvement_inventaire', function (Blueprint $table) {
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $indexes = array_keys($sm->listTableIndexes('mouvement_inventaire'));

                if (! in_array('mi_tenant_created_at_index', $indexes)) {
                    $table->index(['tenant_id', 'created_at'], 'mi_tenant_created_at_index');
                }
                if (! in_array('mi_tenant_type_index', $indexes)) {
                    $table->index(['tenant_id', 'type_mouvement'], 'mi_tenant_type_index');
                }
            });
        }

        // -- facture_paiements (joint via facture.tenant_id) ---------------------
        if (Schema::hasTable('facture_paiements')) {
            Schema::table('facture_paiements', function (Blueprint $table) {
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $indexes = array_keys($sm->listTableIndexes('facture_paiements'));

                if (! in_array('fp_facture_created_at_index', $indexes)) {
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
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $existing = array_keys($sm->listTableIndexes($tableName));

                foreach ($indexNames as $indexName) {
                    if (in_array($indexName, $existing)) {
                        $table->dropIndex($indexName);
                    }
                }
            });
        }
    }
};
