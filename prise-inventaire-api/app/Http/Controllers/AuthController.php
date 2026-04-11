<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\EmployeTenant;
use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class AuthController extends Controller
{
    public function __construct(protected TenantService $tenantService) {}

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

        if (! $tenant) {
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

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Identifiants invalides',
            ], 401);
        }

        $user->derniere_connexion = now();
        $user->save();

        $token = $user->createToken('auth-token', ['*'], now()->addDays(7))->plainTextToken;

        // Chercher la fiche employé liée
        $employe = EmployeTenant::where('admin_user_id', $user->id)->first();

        // Les admins créés par superadmin ont profil_complete = true par défaut
        $profilComplete = $user->profil_complete || $user->role === 'admin';

        return response()->json([
            'success' => true,
            'message' => 'Connexion réussie',
            'user' => [
                'id' => $user->id,
                'nom' => $user->nom,
                'email' => $user->email,
                'role' => $user->role,
                'profil_complete' => $profilComplete,
                'employe_id' => $employe?->id,
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
        $employe = EmployeTenant::where('admin_user_id', $user->id)->first();
        $profilComplete = $user->profil_complete || $user->role === 'admin';

        return response()->json([
            'user' => [
                'id' => $user->id,
                'nom' => $user->nom,
                'email' => $user->email,
                'role' => $user->role,
                'profil_complete' => $profilComplete,
                'employe_id' => $employe?->id,
            ],
            'tenant' => [
                'id' => $tenant->id,
                'nom' => $tenant->nom,
                'slug' => $tenant->slug,
                'plan' => $tenant->plan,
            ],
        ]);
    }

    public function completeProfile(Request $request): JsonResponse
    {
        $request->validate([
            'telephone' => 'nullable|string|max:20',
            'adresse' => 'nullable|string|max:255',
            'ville' => 'nullable|string|max:100',
            'code_postal' => 'nullable|string|max:10',
            'pays' => 'nullable|string|max:100',
            'date_naissance' => 'nullable|date',
            'sexe' => 'nullable|in:M,F,autre',
            'poste' => 'nullable|string|max:100',
            'departement' => 'nullable|string|max:100',
        ]);

        $user = $request->user();
        $employe = EmployeTenant::where('admin_user_id', $user->id)->first();

        if ($employe) {
            $employe->fill($request->only([
                'telephone', 'adresse', 'ville', 'code_postal',
                'pays', 'date_naissance', 'sexe', 'poste', 'departement',
            ]));
            $employe->save();
        }

        $user->profil_complete = true;
        $user->save();

        return response()->json(['success' => true, 'message' => 'Profil complété']);
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        $user = $request->user();
        $employe = EmployeTenant::where('admin_user_id', $user->id)->first();

        if (! $employe) {
            return response()->json(['success' => false, 'message' => 'Fiche employé non trouvée'], 404);
        }

        $request->validate([
            'photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($employe->photo && Storage::disk('public')->exists($employe->photo)) {
            Storage::disk('public')->delete($employe->photo);
        }

        $path = $request->file('photo')->store('photos/employes', 'public');
        $employe->photo = $path;
        $employe->save();

        return response()->json([
            'success' => true,
            'photo_url' => Storage::url($path),
        ]);
    }
}
