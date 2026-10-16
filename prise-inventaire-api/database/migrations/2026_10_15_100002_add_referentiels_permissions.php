<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permission de l'écran « Référentiels » pour les rôles existants (même patron que
 * add_configuration_permissions) : admin = tout, manager = consulter/créer/modifier,
 * autres rôles = rien (à ouvrir depuis l'écran des rôles).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Deux migrations créent role_permissions avec des structures différentes : sur une
        // base qui a gardé la structure héritée (role, permission), il n'y a rien à insérer.
        if (! Schema::hasColumns('role_permissions', ['role_id', 'module'])) {
            return;
        }

        foreach (DB::table('roles_custom')->get() as $role) {
            $admin = $role->nom === 'admin';
            $manager = $role->nom === 'manager';

            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $role->id,
                'module' => 'referentiels',
                'can_view' => $admin || $manager,
                'can_create' => $admin || $manager,
                'can_edit' => $admin || $manager,
                'can_delete' => $admin,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('module', 'referentiels')->delete();
    }
};
