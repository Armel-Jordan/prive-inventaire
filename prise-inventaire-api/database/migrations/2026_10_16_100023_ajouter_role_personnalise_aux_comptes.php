<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rôle personnalisé d'un compte (admin_users) : ses droits par module remplacent ceux du rôle système. La
 * colonne existait seulement sur l'ancienne table « users » : un rôle personnalisé n'était attribuable à
 * personne.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('admin_users', 'role_custom_id')) {
            return;
        }

        Schema::table('admin_users', function (Blueprint $table) {
            $table->unsignedBigInteger('role_custom_id')->nullable()->after('role');
            $table->index('role_custom_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('admin_users', 'role_custom_id')) {
            return;
        }

        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropIndex(['role_custom_id']);
            $table->dropColumn('role_custom_id');
        });
    }
};
