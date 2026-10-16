<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rôles des comptes (admin_users) :
 * - rôle personnalisé (role_custom_id) : ses droits par module remplacent ceux du rôle système. La colonne
 *   n'existait que sur l'ancienne table « users » : un rôle personnalisé n'était attribuable à personne ;
 * - rôle de base en texte : l'énumération MySQL (admin, manager, user) refusait le rôle système « readonly »
 *   (« Data truncated for column 'role' »). Les valeurs existantes sont conservées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->string('role', 20)->default('user')->change();
        });

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
        // Le rôle reste en texte : revenir à l'énumération échouerait sur les comptes « readonly ».
        if (! Schema::hasColumn('admin_users', 'role_custom_id')) {
            return;
        }

        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropIndex(['role_custom_id']);
            $table->dropColumn('role_custom_id');
        });
    }
};
