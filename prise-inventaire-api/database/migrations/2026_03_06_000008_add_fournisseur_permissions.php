<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            // Admin a toutes les permissions (déjà géré dans le code)

            // Manager
            ['role' => 'manager', 'permission' => 'fournisseurs.read'],
            ['role' => 'manager', 'permission' => 'fournisseurs.write'],
            ['role' => 'manager', 'permission' => 'commandes_fournisseur.read'],
            ['role' => 'manager', 'permission' => 'commandes_fournisseur.write'],
            ['role' => 'manager', 'permission' => 'commandes_fournisseur.valider'],
            ['role' => 'manager', 'permission' => 'receptions.read'],
            ['role' => 'manager', 'permission' => 'receptions.write'],

            // User
            ['role' => 'user', 'permission' => 'fournisseurs.read'],
            ['role' => 'user', 'permission' => 'commandes_fournisseur.read'],
            ['role' => 'user', 'permission' => 'receptions.read'],
            ['role' => 'user', 'permission' => 'receptions.write'],

            // Readonly
            ['role' => 'readonly', 'permission' => 'fournisseurs.read'],
            ['role' => 'readonly', 'permission' => 'commandes_fournisseur.read'],
            ['role' => 'readonly', 'permission' => 'receptions.read'],
        ];

        foreach ($permissions as $permission) {
            DB::table('role_permissions')->insertOrIgnore($permission);
        }
    }

    public function down(): void
    {
        $permissions = [
            'fournisseurs.read',
            'fournisseurs.write',
            'fournisseurs.delete',
            'commandes_fournisseur.read',
            'commandes_fournisseur.write',
            'commandes_fournisseur.valider',
            'commandes_fournisseur.annuler',
            'receptions.read',
            'receptions.write',
        ];

        DB::table('role_permissions')->whereIn('permission', $permissions)->delete();
    }
};
