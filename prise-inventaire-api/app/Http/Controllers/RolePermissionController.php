<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Support\Droits;
use App\Support\Permissions;
use App\Support\TenantContext;
use App\Support\TenantRule;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Groupes (rôles) et leurs permissions : droits de base par module (voir, créer, modifier, supprimer) et
 * actions nommées (voir App\Support\Permissions). Les rôles système (tenant_id NULL : admin, manager, user,
 * readonly) sont communs à toutes les entreprises et ne se modifient pas ici ; chaque entreprise crée, modifie,
 * désactive et supprime ses propres groupes, invisibles des autres.
 */
class RolePermissionController extends Controller
{
    /**
     * Rôles visibles par l'entreprise courante : les rôles système et les siens. Les rôles personnalisés
     * créés avant l'isolation n'ont pas d'entreprise : ils restent masqués à toutes.
     */
    private function rolesVisibles(): Builder
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return DB::table('roles_custom')->where(fn ($q) => $q
            ->where(fn ($systeme) => $systeme->whereNull('tenant_id')->where('is_system', true))
            ->orWhere('tenant_id', $tenantId));
    }

    /** Rôle personnalisé de l'entreprise courante ; 403 pour un rôle système, 404 sinon. */
    private function roleModifiable(int $id): object
    {
        $role = $this->rolesVisibles()->find($id);
        abort_if(! $role, 404, 'Rôle non trouvé');
        abort_if($role->is_system || $role->tenant_id === null, 403,
            'Les rôles système sont communs à toutes les entreprises : créez un rôle personnalisé pour adapter les droits.');

        return $role;
    }

    /** Règles du nom : unique dans l'entreprise, et différent des rôles système (casse ignorée). */
    private function reglesNom(string $presence, ?int $ignorer = null): array
    {
        return [$presence, 'string', 'max:50', TenantRule::unique('roles_custom', 'nom', $ignorer),
            function (string $attribut, mixed $valeur, Closure $echec) {
                $reserve = DB::table('roles_custom')->whereNull('tenant_id')->where('is_system', true)
                    ->whereRaw('LOWER(nom) = ?', [mb_strtolower(trim((string) $valeur))])->exists();
                if ($reserve) {
                    $echec('Ce nom est réservé à un rôle système.');
                }
            },
        ];
    }

    /** Permissions d'un rôle par module, en vrais booléens (la base renvoie 0/1). */
    private function permissionsDuRole(int $roleId): Collection
    {
        return DB::table('role_permissions')->where('role_id', $roleId)->get()
            ->map(function (object $permission) {
                foreach (['can_view', 'can_create', 'can_edit', 'can_delete'] as $droit) {
                    $permission->{$droit} = (bool) $permission->{$droit};
                }

                return $permission;
            })
            ->keyBy('module');
    }

    /** Actions nommées réglées par le rôle : code => accordée (les autres suivent leur droit de base). */
    private function actionsDuRole(int $roleId): array
    {
        return DB::table('role_actions')->where('role_id', $roleId)->get()
            ->mapWithKeys(fn (object $action) => [$action->permission => (bool) $action->accorde])->all();
    }

    private function presenterRole(object $role): object
    {
        $role->is_system = (bool) $role->is_system;
        $role->actif = (bool) ($role->actif ?? true);
        $role->permissions = $this->permissionsDuRole($role->id);
        $role->actions = (object) $this->actionsDuRole($role->id);
        $role->nb_comptes = $role->tenant_id === null ? null : $this->comptesDuRole($role->id);

        return $role;
    }

    /** Comptes de l'entreprise rattachés au rôle : rôle principal ou groupe supplémentaire. */
    private function comptesDuRole(int $roleId): int
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $principal = AdminUser::where('tenant_id', $tenantId)->where('role_custom_id', $roleId)->pluck('id');
        $supplementaire = DB::table('admin_user_groupes')->join('admin_users', 'admin_users.id', '=', 'admin_user_groupes.admin_user_id')
            ->where('admin_users.tenant_id', $tenantId)->where('admin_user_groupes.role_id', $roleId)->pluck('admin_users.id');

        return $principal->merge($supplementaire)->unique()->count();
    }

    private function enregistrerPermissions(int $roleId, array $permissions): void
    {
        foreach (Permissions::modules() as $module) {
            $perm = $permissions[$module] ?? [];
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'module' => $module],
                [
                    'can_view' => (bool) ($perm['can_view'] ?? false),
                    'can_create' => (bool) ($perm['can_create'] ?? false),
                    'can_edit' => (bool) ($perm['can_edit'] ?? false),
                    'can_delete' => (bool) ($perm['can_delete'] ?? false),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    /**
     * Actions nommées réglées : code => true (accordée), false (refusée) ou null (suit le droit de base).
     *
     * @throws ValidationException
     */
    private function enregistrerActions(int $roleId, array $actions): void
    {
        foreach ($actions as $code => $accorde) {
            [$module, $action] = array_pad(explode('.', (string) $code, 2), 2, '');
            if (! Permissions::existe((string) $code) || in_array($action, Permissions::CRUD, true) || ! ($accorde === null || is_bool($accorde))) {
                throw ValidationException::withMessages(['actions' => "Permission inconnue : {$code}."]);
            }
            if ($accorde === null) {
                DB::table('role_actions')->where('role_id', $roleId)->where('permission', $code)->delete();

                continue;
            }
            DB::table('role_actions')->updateOrInsert(
                ['role_id' => $roleId, 'permission' => $code],
                ['accorde' => $accorde, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function index(): JsonResponse
    {
        $roles = $this->rolesVisibles()
            ->orderBy('is_system', 'desc')
            ->orderBy('nom')
            ->get()
            ->map(fn (object $role) => $this->presenterRole($role));

        return response()->json($roles);
    }

    public function show(int $id): JsonResponse
    {
        $role = $this->rolesVisibles()->find($id);

        if (! $role) {
            return response()->json(['message' => 'Rôle non trouvé'], 404);
        }

        return response()->json($this->presenterRole($role));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'nom' => $this->reglesNom('required'),
            'description' => 'nullable|string|max:255',
            'permissions' => 'present|array',
            'actions' => 'sometimes|array',
            'actif' => 'sometimes|boolean',
        ]);

        $roleId = DB::transaction(function () use ($request) {
            $roleId = DB::table('roles_custom')->insertGetId([
                'tenant_id' => app(TenantContext::class)->getTenantId(),
                'nom' => trim($request->nom),
                'description' => $request->description,
                'is_system' => false,
                'actif' => $request->boolean('actif', true),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->enregistrerPermissions($roleId, $request->permissions);
            $this->enregistrerActions($roleId, $request->input('actions', []));

            return $roleId;
        });

        return response()->json([
            'success' => true,
            'message' => 'Rôle créé avec succès',
            'role_id' => $roleId,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $role = $this->roleModifiable($id);

        $request->validate([
            'nom' => $this->reglesNom('sometimes', $id),
            'description' => 'nullable|string|max:255',
            'permissions' => 'sometimes|array',
            'actions' => 'sometimes|array',
            'actif' => 'sometimes|boolean',
        ]);

        DB::transaction(function () use ($request, $role, $id) {
            DB::table('roles_custom')->where('id', $id)->update([
                'nom' => $request->filled('nom') ? trim($request->nom) : $role->nom,
                'description' => $request->description ?? $role->description,
                'actif' => $request->has('actif') ? $request->boolean('actif') : (bool) ($role->actif ?? true),
                'updated_at' => now(),
            ]);
            if ($request->has('permissions')) {
                $this->enregistrerPermissions($id, $request->permissions);
            }
            if ($request->has('actions')) {
                $this->enregistrerActions($id, $request->input('actions', []));
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Rôle mis à jour avec succès',
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->roleModifiable($id);

        // Comptes de l'entreprise encore rattachés à ce rôle (rôle principal ou groupe supplémentaire).
        $usersCount = $this->comptesDuRole($id);
        if ($usersCount > 0) {
            return response()->json([
                'message' => "Impossible de supprimer ce rôle car $usersCount utilisateur(s) l'utilisent : désactivez-le plutôt.",
            ], 400);
        }

        DB::table('role_actions')->where('role_id', $id)->delete();
        DB::table('role_permissions')->where('role_id', $id)->delete();
        DB::table('roles_custom')->where('id', $id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Rôle supprimé avec succès',
        ]);
    }

    /** Libellés des modules (écran des rôles, ancien format). */
    public function modules(): JsonResponse
    {
        return response()->json(collect(Permissions::modules())->mapWithKeys(fn (string $module) => [$module => Permissions::libelle($module)]));
    }

    /** Catalogue des permissions : onglets, modules, droits de base et actions nommées, avec leur aide. */
    public function catalogue(): JsonResponse
    {
        return response()->json(['onglets' => Permissions::catalogue()]);
    }

    /**
     * Droits effectifs du compte connecté (groupes, actions nommées, exceptions) : par module pour les menus et
     * les boutons, et code par code (« actions »).
     */
    public function userPermissions(Request $request, Droits $droits): JsonResponse
    {
        $compte = $request->user();
        $role = $droits->role($compte);
        $effectives = $droits->effectives($compte);

        $parModule = [];
        foreach (Permissions::modules() as $module) {
            $parModule[$module] = [
                'module' => $module,
                'can_view' => $effectives["{$module}.voir"] ?? false,
                'can_create' => $effectives["{$module}.creer"] ?? false,
                'can_edit' => $effectives["{$module}.modifier"] ?? false,
                'can_delete' => $effectives["{$module}.supprimer"] ?? false,
            ];
        }

        return response()->json([
            'role' => $role?->nom ?? 'user',
            'role_id' => $role?->id,
            'permissions' => $parModule,
            'actions' => $effectives,
        ]);
    }

    /**
     * Rattache un compte de l'entreprise à l'un de ses rôles personnalisés (role_id null : retour au rôle système
     * du compte). L'attribution écrivait dans l'ancienne table « users » et répondait 500.
     */
    public function assignRole(Request $request, int $userId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $request->validate([
            'role_id' => ['present', 'nullable', Rule::exists('roles_custom', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenantId)->where('is_system', false))],
        ]);

        $compte = AdminUser::where('tenant_id', $tenantId)->findOrFail($userId);
        $compte->update(['role_custom_id' => $request->role_id]);

        return response()->json([
            'success' => true,
            'message' => $request->role_id ? 'Rôle assigné avec succès' : 'Rôle personnalisé retiré',
        ]);
    }
}
