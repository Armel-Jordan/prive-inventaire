<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Handled by 2026_04_11_000003_fix_mouvement_relocalisation_table
        // which creates the table if missing and adds tenant_id + deleted_at safely.
    }

    public function down(): void
    {
        Schema::table('mouvement_relocalisation', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });
    }
};
