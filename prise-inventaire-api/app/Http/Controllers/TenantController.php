<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    public function __construct(protected TenantService $tenantService)
    {
    }

    public function index(): JsonResponse
    {
        $tenants = Tenant::orderBy('nom')->get();
        return response()->json($tenants);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'nom' => 'required|string|max:100',
            'slug' => 'required|string|max:50|unique:tenants,slug|alpha_dash',
            'plan' => 'sometimes|string|in:basic,pro,enterprise',
            'date_expiration' => 'nullable|date',
            'admin_nom' => 'required|string|max:100',
            'admin_email' => 'required|email|unique:admin_users,email',
            'admin_password' => 'required|string|min:8',
        ]);

        $dbName = 'prise_' . Str::slug($request->slug, '_');

        $tenant = Tenant::create([
            'nom' => $request->nom,
            'slug' => strtolower($request->slug),
            'db_name' => $dbName,
            'db_host' => env('DB_HOST', '127.0.0.1'),
            'db_port' => env('DB_PORT', '3306'),
            'db_username' => env('DB_USERNAME', 'root'),
            'db_password' => env('DB_PASSWORD', ''),
            'plan' => $request->plan ?? 'basic',
            'date_expiration' => $request->date_expiration,
        ]);

        // Créer l'utilisateur admin
        $admin = AdminUser::create([
            'tenant_id' => $tenant->id,
            'nom' => $request->admin_nom,
            'email' => $request->admin_email,
            'password' => Hash::make($request->admin_password),
            'role' => 'admin',
        ]);

        // Créer la base de données du tenant
        $dbCreated = $this->tenantService->createTenantDatabase($tenant);

        return response()->json([
            'success' => true,
            'message' => 'Tenant créé avec succès',
            'tenant' => $tenant,
            'admin' => [
                'id' => $admin->id,
                'nom' => $admin->nom,
                'email' => $admin->email,
            ],
            'database_created' => $dbCreated,
        ], 201);
    }

    public function show($id): JsonResponse
    {
        $tenant = Tenant::with('users')->findOrFail($id);
        return response()->json($tenant);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $request->validate([
            'nom' => 'sometimes|string|max:100',
            'actif' => 'sometimes|boolean',
            'plan' => 'sometimes|string|in:basic,pro,enterprise',
            'date_expiration' => 'nullable|date',
        ]);

        $tenant->fill($request->only(['nom', 'actif', 'plan', 'date_expiration']));
        $tenant->save();

        return response()->json([
            'success' => true,
            'message' => 'Tenant modifié avec succès',
            'tenant' => $tenant,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->actif = false;
        $tenant->save();

        return response()->json([
            'success' => true,
            'message' => 'Tenant désactivé avec succès',
        ]);
    }
}
