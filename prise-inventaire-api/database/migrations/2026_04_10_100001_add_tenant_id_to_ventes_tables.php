<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'clients',
            'com_client_entete',
            'factures',
            'bons_livraison',
            'tournees',
            'camions',
            'devis',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->unsignedBigInteger('tenant_id')->nullable()->after('id')->index();
                });
            }
        }
    }

    public function down(): void
    {
        $tables = [
            'clients',
            'com_client_entete',
            'factures',
            'bons_livraison',
            'tournees',
            'camions',
            'devis',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }
    }
};
