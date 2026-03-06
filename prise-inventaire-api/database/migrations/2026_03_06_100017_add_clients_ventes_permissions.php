<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $newModules = [
            'clients',
            'commandes_client',
            'factures',
            'bons_livraison',
            'camions',
            'tournees',
            'mouvements_inventaire',
            'localisations',
            'zones_preparation'
        ];

        $roles = DB::table('roles_custom')->get();

        foreach ($roles as $role) {
            foreach ($newModules as $module) {
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
                    $canDelete = ($module !== 'clients' && $module !== 'camions');
                } elseif ($role->nom === 'user') {
                    if (in_array($module, ['commandes_client', 'bons_livraison'])) {
                        $canCreate = true;
                        $canEdit = true;
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
            'clients',
            'commandes_client',
            'factures',
            'bons_livraison',
            'camions',
            'tournees',
            'mouvements_inventaire',
            'localisations',
            'zones_preparation'
        ];
        DB::table('role_permissions')->whereIn('module', $modules)->delete();
    }
};
