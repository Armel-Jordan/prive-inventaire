<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $newModules = [
            'fournisseurs',
            'commandes_fournisseur',
            'receptions'
        ];

        $roles = DB::table('roles_custom')->get();

        foreach ($roles as $role) {
            foreach ($newModules as $module) {
                $exists = DB::table('role_permissions')
                    ->where('role_id', $role->id)
                    ->where('module', $module)
                    ->exists();

                if ($exists) continue;

                $canView = true;
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
                    $canDelete = ($module === 'receptions');
                } elseif ($role->nom === 'user') {
                    if ($module === 'receptions') {
                        $canCreate = true;
                        $canEdit = true;
                    }
                }

                DB::table('role_permissions')->insert([
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
