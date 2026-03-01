<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(protected TenantService $tenantService)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'tenant_slug' => 'required|string',
        ]);

        $tenant = Tenant::where('slug', $request->tenant_slug)
            ->where('actif', true)
            ->first();

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Entreprise non trouvée ou inactive',
            ], 404);
        }

        if ($tenant->isExpired()) {
            return response()->json([
                'success' => false,
                'message' => 'Votre abonnement a expiré',
            ], 403);
        }

        $user = AdminUser::where('tenant_id', $tenant->id)
            ->where('email', $request->email)
            ->where('actif', true)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Identifiants invalides',
            ], 401);
        }

        $user->derniere_connexion = now();
        $user->save();

        $token = $user->createToken('auth-token', ['*'], now()->addDays(7))->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Connexion réussie',
            'user' => [
                'id' => $user->id,
                'nom' => $user->nom,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'tenant' => [
                'id' => $tenant->id,
                'nom' => $tenant->nom,
                'slug' => $tenant->slug,
                'plan' => $tenant->plan,
            ],
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Déconnexion réussie',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = $user->tenant;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'nom' => $user->nom,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'tenant' => [
                'id' => $tenant->id,
                'nom' => $tenant->nom,
                'slug' => $tenant->slug,
                'plan' => $tenant->plan,
            ],
        ]);
    }
}
