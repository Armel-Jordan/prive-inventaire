<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $newModules = [
            'configuration',
            'gestion_prix',
            'alertes_config',
            'devis',
            'comptabilite',
            'previsions_stock',
        ];

        $roles = DB::table('roles_custom')->get();

        foreach ($roles as $role) {
            foreach ($newModules as $module) {
                $canView = false;
                $canCreate = false;
                $canEdit = false;
                $canDelete = false;

                // Seul l'admin a accès à la configuration
                if ($role->nom === 'admin') {
                    $canView = true;
                    $canCreate = true;
                    $canEdit = true;
                    $canDelete = true;
                } elseif ($role->nom === 'manager') {
                    // Manager peut voir mais pas modifier la config système
                    if ($module === 'configuration') {
                        $canView = false;
                    } else {
                        $canView = true;
                        $canCreate = true;
                        $canEdit = true;
                    }
                } elseif ($role->nom === 'user') {
                    // User peut voir devis et comptabilité en lecture seule
                    if (in_array($module, ['devis', 'comptabilite', 'previsions_stock'])) {
                        $canView = true;
                    }
                }

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
        $modules = [
            'configuration',
            'gestion_prix',
            'alertes_config',
            'devis',
            'comptabilite',
            'previsions_stock',
        ];
        DB::table('role_permissions')->whereIn('module', $modules)->delete();
    }
};
