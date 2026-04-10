<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'com_four_entete',
            'zones_preparation',
            'transferts_planifies',
            'approbations',
            'mouvement_inventaire',
            'produit_localisations',
            'inventaire_scan',
            'alertes_stock',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->unsignedBigInteger('tenant_id')->nullable()->after('id')->index();
                });
            }
        }
    }

    public function down(): void
    {
        $tables = [
            'com_four_entete',
            'zones_preparation',
            'transferts_planifies',
            'approbations',
            'mouvement_inventaire',
            'produit_localisations',
            'inventaire_scan',
            'alertes_stock',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropColumn('tenant_id');
                });
            }
        }
    }
};
