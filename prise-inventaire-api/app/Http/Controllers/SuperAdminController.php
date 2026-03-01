<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class SuperAdminController extends Controller
{
    /**
     * Login Super Admin (sans tenant_slug)
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = AdminUser::where('email', $request->email)
            ->where('role', 'super_admin')
            ->where('actif', true)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Identifiants invalides ou accès non autorisé',
            ], 401);
        }

        $user->derniere_connexion = now();
        $user->save();

        $token = $user->createToken('super-admin-token', ['super-admin'], now()->addDays(1))->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Connexion Super Admin réussie',
            'user' => [
                'id' => $user->id,
                'nom' => $user->nom,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'token' => $token,
        ]);
    }

    /**
     * Liste tous les tenants
     */
    public function getTenants(): JsonResponse
    {
        $tenants = Tenant::withCount('adminUsers')
            ->orderBy('nom')
            ->get();

        return response()->json($tenants);
    }

    /**
     * Créer un nouveau tenant
     */
    public function createTenant(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'required|string|max:100',
            'slug' => 'required|string|max:50|unique:tenants,slug|alpha_dash',
            'plan' => 'required|in:starter,pro,enterprise',
            'date_expiration' => 'nullable|date|after:today',
        ]);

        $tenant = Tenant::create([
            'nom' => $validated['nom'],
            'slug' => strtolower($validated['slug']),
            'plan' => $validated['plan'],
            'actif' => true,
            'date_expiration' => $validated['date_expiration'] ?? now()->addYear(),
        ]);

        return response()->json([
            'message' => 'Tenant créé avec succès',
            'tenant' => $tenant,
        ], 201);
    }

    /**
     * Modifier un tenant
     */
    public function updateTenant(Request $request, $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $validated = $request->validate([
            'nom' => 'sometimes|string|max:100',
            'slug' => ['sometimes', 'string', 'max:50', 'alpha_dash', Rule::unique('tenants')->ignore($id)],
            'plan' => 'sometimes|in:starter,pro,enterprise',
            'actif' => 'sometimes|boolean',
            'date_expiration' => 'nullable|date',
        ]);

        if (isset($validated['slug'])) {
            $validated['slug'] = strtolower($validated['slug']);
        }

        $tenant->update($validated);

        return response()->json([
            'message' => 'Tenant mis à jour',
            'tenant' => $tenant,
        ]);
    }

    /**
     * Supprimer un tenant
     */
    public function deleteTenant($id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->delete();

        return response()->json(['message' => 'Tenant supprimé']);
    }

    /**
     * Liste les admins d'un tenant
     */
    public function getTenantAdmins($tenantId): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);

        $admins = AdminUser::where('tenant_id', $tenantId)
            ->orderBy('nom')
            ->get(['id', 'nom', 'email', 'role', 'actif', 'derniere_connexion', 'created_at']);

        return response()->json([
            'tenant' => $tenant,
            'admins' => $admins,
        ]);
    }

    /**
     * Créer un admin pour un tenant
     */
    public function createTenantAdmin(Request $request, $tenantId): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);

        $validated = $request->validate([
            'nom' => 'required|string|max:100',
            'email' => 'required|email|unique:admin_users,email',
            'password' => 'required|string|min:6',
            'role' => 'required|in:admin,manager,user',
        ]);

        $admin = AdminUser::create([
            'tenant_id' => $tenant->id,
            'nom' => $validated['nom'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'actif' => true,
        ]);

        return response()->json([
            'message' => 'Admin créé avec succès',
            'admin' => $admin,
        ], 201);
    }

    /**
     * Modifier un admin
     */
    public function updateTenantAdmin(Request $request, $tenantId, $adminId): JsonResponse
    {
        $admin = AdminUser::where('tenant_id', $tenantId)->findOrFail($adminId);

        $validated = $request->validate([
            'nom' => 'sometimes|string|max:100',
            'email' => ['sometimes', 'email', Rule::unique('admin_users')->ignore($adminId)],
            'password' => 'sometimes|string|min:6',
            'role' => 'sometimes|in:admin,manager,user',
            'actif' => 'sometimes|boolean',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $admin->update($validated);

        return response()->json([
            'message' => 'Admin mis à jour',
            'admin' => $admin,
        ]);
    }

    /**
     * Supprimer un admin
     */
    public function deleteTenantAdmin($tenantId, $adminId): JsonResponse
    {
        $admin = AdminUser::where('tenant_id', $tenantId)->findOrFail($adminId);
        $admin->delete();

        return response()->json(['message' => 'Admin supprimé']);
    }

    /**
     * Statistiques globales
     */
    public function getStats(): JsonResponse
    {
        return response()->json([
            'total_tenants' => Tenant::count(),
            'tenants_actifs' => Tenant::where('actif', true)->count(),
            'total_admins' => AdminUser::where('role', '!=', 'super_admin')->count(),
            'tenants_expires' => Tenant::where('date_expiration', '<', now())->count(),
        ]);
    }
}
