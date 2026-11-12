<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Délégation des groupes et des accès (plan de parité fripv2, § 33) : actions « roles.groupes » (gérer les groupes
 * et leurs permissions) et « roles.acces » (régler les accès d'un compte), qui remplacent la réserve aux
 * administrateurs des routes des groupes et des accès.
 *
 * Ajouts seulement : un réglage « refusée » de ces deux actions pour chaque groupe existant (rôles système et
 * groupes des entreprises), sauf le rôle administrateur, pour qu'elles restent aux administrateurs tant qu'un
 * administrateur ne les accorde pas à un groupe.
 */
return new class extends Migration
{
    private const ACTIONS = ['roles.groupes', 'roles.acces'];

    public function up(): void
    {
        if (! Schema::hasTable('role_actions') || ! Schema::hasTable('roles_custom')) {
            return;
        }
        $groupes = DB::table('roles_custom')
            ->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))
            ->where(fn ($q) => $q->whereNotNull('tenant_id')->orWhere('nom', '!=', 'admin'))
            ->pluck('id');
        foreach ($groupes as $roleId) {
            foreach (self::ACTIONS as $permission) {
                DB::table('role_actions')->insertOrIgnore([
                    'role_id' => $roleId, 'permission' => $permission, 'accorde' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_actions')->whereIn('permission', self::ACTIONS)->where('accorde', false)->delete();
    }
};
