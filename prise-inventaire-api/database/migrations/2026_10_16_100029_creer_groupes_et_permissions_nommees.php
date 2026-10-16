<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Groupes et permissions fines (plan de parité fripv2, phase 6.2), sans rien changer aux droits actuels :
 * - un groupe (rôle) peut être désactivé sans être supprimé ;
 * - actions nommées réglées par groupe (role_actions) ; sans réglage, elles suivent un droit de base du module ;
 * - un compte peut appartenir à des groupes supplémentaires (union des droits) ;
 * - exceptions par compte : accorder ou refuser une permission (le refus l'emporte).
 *
 * Nouveaux modules (écrans qui suivaient un autre module) : valorisation, comptes clients, espace client.
 * Les actions autrefois réservées par rôle (administrateur, responsable) sont réglées pour les rôles système
 * comme aujourd'hui.
 */
return new class extends Migration
{
    /** Action nommée => rôles système qui l'ont (les autres non). */
    private const ACTIONS_SYSTEME = [
        'inventaires.prises' => ['admin', 'manager'],
        'fournisseurs.comptes_bancaires' => ['admin', 'manager'],
        'clients.categories' => ['admin'],
        'comptabilite.ecrire' => ['admin', 'manager'],
        'comptabilite.plan' => ['admin', 'manager'],
        'comptabilite.cloturer' => ['admin'],
    ];

    /**
     * Nouveaux modules : droits [voir, créer, modifier, supprimer] des rôles système (ceux d'aujourd'hui), et
     * module dont un rôle personnalisé reprend le droit de consulter.
     */
    private const NOUVEAUX_MODULES = [
        'valorisation' => [
            'systeme' => ['admin' => [1, 1, 1, 1], 'manager' => [1, 0, 0, 0], 'user' => [0, 0, 0, 0], 'readonly' => [0, 0, 0, 0]],
            'voir_depuis' => null,
        ],
        'comptes_clients' => [
            'systeme' => ['admin' => [1, 1, 1, 1], 'manager' => [1, 0, 0, 0], 'user' => [1, 0, 0, 0], 'readonly' => [1, 0, 0, 0]],
            'voir_depuis' => 'factures',
        ],
        'espace_client' => [
            'systeme' => ['admin' => [1, 1, 1, 1], 'manager' => [1, 1, 1, 1], 'user' => [1, 0, 0, 0], 'readonly' => [1, 0, 0, 0]],
            'voir_depuis' => 'clients',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('roles_custom', 'actif')) {
            Schema::table('roles_custom', function (Blueprint $table) {
                $table->boolean('actif')->default(true)->after('is_system');
            });
        }

        if (! Schema::hasTable('role_actions')) {
            Schema::create('role_actions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('role_id');
                // Code « module.action » (voir App\Support\Permissions).
                $table->string('permission', 80);
                $table->boolean('accorde');
                $table->timestamps();

                $table->foreign('role_id')->references('id')->on('roles_custom')->cascadeOnDelete();
                $table->unique(['role_id', 'permission']);
            });
        }

        if (! Schema::hasTable('admin_user_groupes')) {
            Schema::create('admin_user_groupes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('admin_user_id');
                $table->unsignedBigInteger('role_id');
                $table->timestamps();

                $table->foreign('admin_user_id')->references('id')->on('admin_users')->cascadeOnDelete();
                $table->foreign('role_id')->references('id')->on('roles_custom')->cascadeOnDelete();
                $table->unique(['admin_user_id', 'role_id']);
            });
        }

        if (! Schema::hasTable('admin_user_permissions')) {
            Schema::create('admin_user_permissions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('admin_user_id');
                $table->string('permission', 80);
                // true : accordée en plus des groupes ; false : refusée même si un groupe l'accorde.
                $table->boolean('accorde');
                $table->timestamps();

                $table->foreign('admin_user_id')->references('id')->on('admin_users')->cascadeOnDelete();
                $table->unique(['admin_user_id', 'permission']);
            });
        }

        $roles = DB::table('roles_custom')->get(['id', 'nom', 'is_system', 'tenant_id']);
        foreach (self::NOUVEAUX_MODULES as $module => $regles) {
            foreach ($roles as $role) {
                // Anciens rôles personnalisés sans entreprise : masqués à toutes, laissés tels quels.
                if (! $role->is_system && $role->tenant_id === null) {
                    continue;
                }
                if ($role->is_system && $role->tenant_id === null) {
                    $droits = $regles['systeme'][$role->nom] ?? [0, 0, 0, 0];
                } else {
                    $voir = $regles['voir_depuis'] !== null && (bool) DB::table('role_permissions')
                        ->where('role_id', $role->id)->where('module', $regles['voir_depuis'])->value('can_view');
                    $droits = [$voir ? 1 : 0, 0, 0, 0];
                }
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $role->id, 'module' => $module,
                    'can_view' => (bool) $droits[0], 'can_create' => (bool) $droits[1],
                    'can_edit' => (bool) $droits[2], 'can_delete' => (bool) $droits[3],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        foreach ($roles->filter(fn ($role) => $role->is_system && $role->tenant_id === null) as $role) {
            foreach (self::ACTIONS_SYSTEME as $permission => $autorises) {
                DB::table('role_actions')->insertOrIgnore([
                    'role_id' => $role->id, 'permission' => $permission, 'accorde' => in_array($role->nom, $autorises, true),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('module', array_keys(self::NOUVEAUX_MODULES))->delete();
        Schema::dropIfExists('admin_user_permissions');
        Schema::dropIfExists('admin_user_groupes');
        Schema::dropIfExists('role_actions');
        if (Schema::hasColumn('roles_custom', 'actif')) {
            Schema::table('roles_custom', function (Blueprint $table) {
                $table->dropColumn('actif');
            });
        }
    }
};
