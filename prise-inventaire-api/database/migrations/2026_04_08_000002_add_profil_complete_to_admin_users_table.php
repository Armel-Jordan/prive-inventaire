<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('admin_users', 'profil_complete')) {
            return;
        }

        Schema::table('admin_users', function (Blueprint $table) {
            $table->boolean('profil_complete')->default(false)->after('actif');
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropColumn('profil_complete');
        });
    }
};
