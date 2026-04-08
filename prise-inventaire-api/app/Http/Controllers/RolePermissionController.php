<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        'fiches_employes',
    ];

    public function index(): JsonResponse
    {
        $roles = DB::table('roles_custom')
            ->orderBy('is_system', 'desc')
            ->orderBy('nom')
            ->get();

        foreach ($roles as $role) {
            $role->permissions = DB::table('role_permissions')
                ->where('role_id', $role->id)
                ->get()
                ->keyBy('module');
        }

        return response()->json($roles);
    }

    public function show(int $id): JsonResponse
    {
        $role = DB::table('roles_custom')->find($id);

        if (!$role) {
            return response()->json(['message' => 'Rôle non trouvé'], 404);
        }

        $role->permissions = DB::table('role_permissions')
            ->where('role_id', $id)
            ->get();

        return response()->json($role);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'nom' => 'required|string|max:50|unique:roles_custom,nom',
            'description' => 'nullable|string|max:255',
            'permissions' => 'required|array',
        ]);

        $roleId = DB::table('roles_custom')->insertGetId([
            'nom' => $request->nom,
            'description' => $request->description,
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($this->allModules as $module) {
            $perm = $request->permissions[$module] ?? [];
            DB::table('role_permissions')->insert([
                'role_id' => $roleId,
                'module' => $module,
                'can_view' => $perm['can_view'] ?? false,
                'can_create' => $perm['can_create'] ?? false,
                'can_edit' => $perm['can_edit'] ?? false,
                'can_delete' => $perm['can_delete'] ?? false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Rôle créé avec succès',
            'role_id' => $roleId,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $role = DB::table('roles_custom')->find($id);

        if (!$role) {
            return response()->json(['message' => 'Rôle non trouvé'], 404);
        }

        $request->validate([
            'nom' => 'sometimes|string|max:50|unique:roles_custom,nom,' . $id,
            'description' => 'nullable|string|max:255',
            'permissions' => 'sometimes|array',
        ]);

        // Mettre à jour le rôle
        DB::table('roles_custom')->where('id', $id)->update([
            'nom' => $request->nom ?? $role->nom,
            'description' => $request->description ?? $role->description,
            'updated_at' => now(),
        ]);

        // Mettre à jour les permissions si fournies
        if ($request->has('permissions')) {
            foreach ($this->allModules as $module) {
                $perm = $request->permissions[$module] ?? [];
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $id, 'module' => $module],
                    [
                        'can_view' => $perm['can_view'] ?? false,
                        'can_create' => $perm['can_create'] ?? false,
                        'can_edit' => $perm['can_edit'] ?? false,
                        'can_delete' => $perm['can_delete'] ?? false,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Rôle mis à jour avec succès',
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $role = DB::table('roles_custom')->find($id);

        if (!$role) {
            return response()->json(['message' => 'Rôle non trouvé'], 404);
        }

        if ($role->is_system) {
            return response()->json(['message' => 'Impossible de supprimer un rôle système'], 403);
        }

        // Vérifier si des utilisateurs utilisent ce rôle
        $usersCount = DB::table('users')->where('role_custom_id', $id)->count();
        if ($usersCount > 0) {
            return response()->json([
                'message' => "Impossible de supprimer ce rôle car $usersCount utilisateur(s) l'utilisent"
            ], 400);
        }

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
        ];

        return response()->json($moduleLabels);
    }

    public function userPermissions(Request $request): JsonResponse
    {
        $user = $request->user();

        // Récupérer le rôle de l'utilisateur
        $roleId = $user->role_custom_id;

        // Si pas de rôle assigné, chercher par le nom du rôle
        if (!$roleId && $user->role) {
            $role = DB::table('roles_custom')->where('nom', $user->role)->first();
            $roleId = $role?->id;
        }

        // Par défaut, utiliser le rôle "user"
        if (!$roleId) {
            $role = DB::table('roles_custom')->where('nom', 'user')->first();
            $roleId = $role?->id;
        }

        $permissions = DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->get()
            ->keyBy('module');

        $role = DB::table('roles_custom')->find($roleId);

        return response()->json([
            'role' => $role?->nom ?? 'user',
            'role_id' => $roleId,
            'permissions' => $permissions,
        ]);
    }

    public function assignRole(Request $request, int $userId): JsonResponse
    {
        $request->validate([
            'role_id' => 'required|exists:roles_custom,id',
        ]);

        DB::table('users')->where('id', $userId)->update([
            'role_custom_id' => $request->role_id,
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Rôle assigné avec succès',
        ]);
    }
}
