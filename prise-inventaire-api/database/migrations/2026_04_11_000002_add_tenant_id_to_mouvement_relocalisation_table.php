<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mouvement_relocalisation')) {
            return;
        }

        if (Schema::hasColumn('mouvement_relocalisation', 'tenant_id')) {
            return;
        }

        Schema::table('mouvement_relocalisation', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('mouvement_relocalisation', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });
    }
};
