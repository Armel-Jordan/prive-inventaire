<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Support\Droits;
use App\Support\TenantContext;
use App\Support\TenantRule;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Rôles et permissions par module. Les rôles système (tenant_id NULL : admin, manager, user, readonly)
 * sont communs à toutes les entreprises et ne se modifient pas ici ; chaque entreprise crée, modifie et
 * supprime ses propres rôles personnalisés, invisibles des autres.
 */
class RolePermissionController extends Controller
{
    private array $allModules = [
        'dashboard', 'inventaires', 'statistiques', 'comparaison', 'alertes',
        'historique', 'tracabilite', 'relocalisation', 'planification',
        'approbations', 'rapports', 'inventaire_tournant', 'produits',
        'secteurs', 'employes', 'roles', 'fournisseurs', 'commandes_fournisseur',
        'receptions', 'clients', 'commandes_client', 'factures', 'bons_livraison',
        'camions', 'tournees', 'zones_preparation', 'devis', 'comptabilite',
        'previsions_stock', 'gestion_prix', 'alertes_config', 'configuration',
        'fiches_employes', 'referentiels',
    ];

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

    private function presenterRole(object $role): object
    {
        $role->is_system = (bool) $role->is_system;
        $role->permissions = $this->permissionsDuRole($role->id);

        return $role;
    }

    private function enregistrerPermissions(int $roleId, array $permissions): void
    {
        foreach ($this->allModules as $module) {
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
        ]);

        $roleId = DB::table('roles_custom')->insertGetId([
            'tenant_id' => app(TenantContext::class)->getTenantId(),
            'nom' => trim($request->nom),
            'description' => $request->description,
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->enregistrerPermissions($roleId, $request->permissions);

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
        ]);

        DB::table('roles_custom')->where('id', $id)->update([
            'nom' => $request->filled('nom') ? trim($request->nom) : $role->nom,
            'description' => $request->description ?? $role->description,
            'updated_at' => now(),
        ]);

        if ($request->has('permissions')) {
            $this->enregistrerPermissions($id, $request->permissions);
        }

        return response()->json([
            'success' => true,
            'message' => 'Rôle mis à jour avec succès',
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->roleModifiable($id);

        // Comptes de l'entreprise encore rattachés à ce rôle.
        $usersCount = AdminUser::where('tenant_id', app(TenantContext::class)->getTenantId())
            ->where('role_custom_id', $id)->count();
        if ($usersCount > 0) {
            return response()->json([
                'message' => "Impossible de supprimer ce rôle car $usersCount utilisateur(s) l'utilisent",
            ], 400);
        }

        DB::table('role_permissions')->where('role_id', $id)->delete();
        DB::table('roles_custom')->where('id', $id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Rôle supprimé avec succès',
        ]);
    }

    public function modules(): JsonResponse
    {
        $moduleLabels = [
            'dashboard' => 'Tableau de bord',
            'inventaires' => 'Inventaires',
            'statistiques' => 'Statistiques',
            'comparaison' => 'Comparaison',
            'alertes' => 'Alertes Stock',
            'historique' => 'Historique',
            'tracabilite' => 'Traçabilité',
            'relocalisation' => 'Relocalisation',
            'planification' => 'Planification',
            'approbations' => 'Approbations',
            'rapports' => 'Rapports',
            'inventaire_tournant' => 'Inventaire Tournant',
            'produits' => 'Produits',
            'secteurs' => 'Secteurs',
            'employes' => 'Employés',
            'roles' => 'Gestion des rôles',
            'fournisseurs' => 'Fournisseurs',
            'commandes_fournisseur' => 'Commandes Fournisseur',
            'receptions' => 'Réceptions',
            'clients' => 'Clients',
            'commandes_client' => 'Commandes Client',
            'factures' => 'Factures',
            'bons_livraison' => 'Bons de Livraison',
            'camions' => 'Camions',
            'tournees' => 'Tournées',
            'zones_preparation' => 'Zones Préparation',
            'devis' => 'Devis',
            'comptabilite' => 'Comptabilité',
            'previsions_stock' => 'Prévisions Stock',
            'gestion_prix' => 'Gestion des Prix',
            'alertes_config' => 'Configuration Alertes',
            'configuration' => 'Configuration Système',
            'fiches_employes' => 'Fiches Employés',
            'referentiels' => 'Référentiels',
        ];

        return response()->json($moduleLabels);
    }

    public function userPermissions(Request $request, Droits $droits): JsonResponse
    {
        // Même rôle que celui qu'applique l'API (middleware « droits ») : rôle personnalisé de l'entreprise,
        // sinon rôle système du même nom ; un administrateur garde tous les droits.
        $role = $droits->role($request->user());

        $permissions = $role ? $this->permissionsDuRole($role->id) : collect();

        return response()->json([
            'role' => $role?->nom ?? 'user',
            'role_id' => $role?->id,
            'permissions' => $permissions,
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
