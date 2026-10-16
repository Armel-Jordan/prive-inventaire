<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permissions héritées « rôle + permission » (ex. scans.read), lues par RoleController et CheckPermission.
 * La table a été renommée role_permissions_anciennes (migration isoler_roles_par_entreprise) pour laisser
 * role_permissions aux permissions par module. Une base qui ne l'a jamais eue n'en a aucune.
 */
class PermissionsAnciennes
{
    /** Permissions d'un rôle, ou de tous les rôles si $role vaut null. */
    public static function pour(?string $role): Collection
    {
        $table = collect(['role_permissions_anciennes', 'role_permissions'])
            ->first(fn (string $nom) => Schema::hasTable($nom) && Schema::hasColumns($nom, ['role', 'permission']));
        if (! $table) {
            return collect();
        }

        return DB::table($table)
            ->when($role !== null, fn ($requete) => $requete->where('role', $role))
            ->distinct()
            ->pluck('permission');
    }
}
