<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Les nouveaux modules pour les commandes fournisseurs
        $newModules = ['fournisseurs', 'commandes_fournisseur', 'receptions'];

        // Récupérer les rôles existants
        $roles = DB::table('roles_custom')->get();

        foreach ($roles as $role) {
            foreach ($newModules as $module) {
                // Définir les permissions selon le rôle
                $canView = true; // Tous peuvent voir
                $canCreate = false;
                $canEdit = false;
                $canDelete = false;

                if ($role->nom === 'admin') {
                    $canCreate = true;
                    $canEdit = true;
                    $canDelete = true;
                } elseif ($role->nom === 'manager') {
                    $canCreate = true;
                    $canEdit = true;
                    $canDelete = false;
                } elseif ($role->nom === 'user') {
                    // User peut créer des réceptions
                    $canCreate = ($module === 'receptions');
                    $canEdit = false;
                    $canDelete = false;
                }
                // readonly = lecture seule (valeurs par défaut)

                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $role->id,
                    'module' => $module,
                    'can_view' => $canView,
                    'can_create' => $canCreate,
                    'can_edit' => $canEdit,
                    'can_delete' => $canDelete,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $modules = ['fournisseurs', 'commandes_fournisseur', 'receptions'];
        DB::table('role_permissions')->whereIn('module', $modules)->delete();
    }
};
