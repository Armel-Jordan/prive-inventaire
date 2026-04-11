<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'tenants',
        'admin_users',
        'secteurs',
        'employes',
        'produits',
        'mouvement_inventaire',
        'mouvement_relocalisation',
        'transferts_planifies',
        'approbations',
        'notifications',
        'audit_logs',
        'fournisseurs',
        'clients',
        'configurations',
        'tournees',
        'camions',
        'zones_preparation',
        'roles_custom',
        'role_permissions',
    ];

    private array $conditionalTables = [
        'com_four_entete',
        'com_four_ligne',
        'com_client_entete',
        'com_client_ligne',
        'factures',
        'facture_ligne',
        'facture_echeances',
        'facture_paiements',
        'bons_livraison',
        'bon_livraison_ligne',
        'tournee_bons',
        'reception_arrivages_ligne',
        'historique_prix_achat',
        'produit_localisations',
        'mouvements_vente',
        'client_conditions_paiement',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->softDeletes();
                });
            }
        }

        foreach ($this->conditionalTables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        $allTables = array_merge($this->tables, $this->conditionalTables);
        foreach ($allTables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropSoftDeletes();
                });
            }
        }
    }
};
