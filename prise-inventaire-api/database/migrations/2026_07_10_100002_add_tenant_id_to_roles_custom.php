<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Isolation des rôles personnalisés par tenant.
 *
 * tenant_id NULL   -> rôle système partagé par tous les tenants (admin/manager/
 *                     user/readonly, is_system = true).
 * tenant_id défini -> rôle custom propre à un tenant.
 *
 * Le scoping effectif des requêtes roles_custom / role_permissions (query builder)
 * et de CheckPermission sera traité dans le chantier C7.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('roles_custom') && ! Schema::hasColumn('roles_custom', 'tenant_id')) {
            Schema::table('roles_custom', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('roles_custom') && Schema::hasColumn('roles_custom', 'tenant_id')) {
            Schema::table('roles_custom', function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }
    }
};
