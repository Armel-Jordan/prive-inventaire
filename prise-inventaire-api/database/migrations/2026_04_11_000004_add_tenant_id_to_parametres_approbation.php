<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('parametres_approbation')) {
            return;
        }

        if (Schema::hasColumn('parametres_approbation', 'tenant_id')) {
            return;
        }

        Schema::table('parametres_approbation', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('parametres_approbation') && Schema::hasColumn('parametres_approbation', 'tenant_id')) {
            Schema::table('parametres_approbation', function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }
    }
};
