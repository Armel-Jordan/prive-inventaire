<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\Configuration;
use App\Models\EmployeTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class EmployeTenantController extends Controller
{
    public function index(): JsonResponse
    {
        $employes = EmployeTenant::with('adminUser:id,role,actif')
            ->where('tenant_id', auth()->user()->tenant_id)
            ->orderBy('nom')
            ->get();

        return response()->json($employes);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'numero'   => 'nullable|string|max:20|unique:employes,numero',
            'nom'      => 'required|string|max:100',
            'prenom'   => 'nullable|string|max:100',
            'email'    => 'nullable|email|max:100',
            'role'     => 'nullable|in:user,manager,admin',
            'password' => 'required_with:role|nullable|string|min:6',
        ]);

        $tenantId = auth()->user()->tenant_id;

        // Générer le numéro automatiquement si non fourni
        $numero = $request->numero;
        if (empty($numero)) {
            $config = Configuration::pourEntite('employe', $request->attributes->get('tenant')->id);
            if ($config && $config->auto_increment) {
                $numero = $config->genererNumero();
                $config->incrementer();
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Le numéro est requis (pas de configuration auto-increment)',
                ], 422);
            }
        }

        // Créer ou lier un AdminUser si un rôle est fourni
        $adminUserId = null;
        if ($request->filled('role') && $request->role !== 'remove') {
            $existing = $request->filled('email')
                ? AdminUser::where('email', $request->email)->where('tenant_id', $tenantId)->first()
                : null;

            if ($existing) {
                // Lier au compte existant
                $existing->update(['role' => $request->role]);
                $adminUserId = $existing->id;
            } else {
                if (!$request->filled('password')) {
                    return response()->json(['success' => false, 'message' => 'Le mot de passe est requis'], 422);
                }
                $adminUser = AdminUser::create([
                    'tenant_id' => $tenantId,
                    'nom'       => trim($request->nom . ' ' . ($request->prenom ?? '')),
                    'email'     => $request->email,
                    'password'  => Hash::make($request->password),
                    'role'      => $request->role,
                    'actif'     => true,
                ]);
                $adminUserId = $adminUser->id;
            }
        }

        $employe = EmployeTenant::create([
            'tenant_id'     => $tenantId,
            'admin_user_id' => $adminUserId,
            'numero'        => $numero,
            'nom'           => $request->nom,
            'prenom'        => $request->prenom,
            'email'         => $request->email,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Employé créé avec succès',
            'employe' => $employe->load('adminUser:id,role,actif'),
        ], 201);
    }

    public function show($id): JsonResponse
    {
        $employe = EmployeTenant::with('adminUser:id,role,actif')->findOrFail($id);
        return response()->json($employe);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $employe = EmployeTenant::findOrFail($id);

        $request->validate([
            'numero'   => 'sometimes|string|max:20|unique:employes,numero,' . $id,
            'nom'      => 'sometimes|string|max:100',
            'prenom'   => 'nullable|string|max:100',
            'email'    => 'nullable|email|max:100',
            'actif'    => 'sometimes|boolean',
            'role'     => 'nullable|in:user,manager,admin,remove',
            'password' => 'nullable|string|min:6',
        ]);

        $employe->fill($request->only(['numero', 'nom', 'prenom', 'email', 'actif']));

        // Gérer l'accès application
        if ($request->has('role')) {
            if ($request->role === 'remove') {
                // Supprimer l'accès
                if ($employe->adminUser) {
                    $employe->adminUser->delete();
                    $employe->admin_user_id = null;
                }
            } elseif ($request->filled('role')) {
                if ($employe->adminUser) {
                    // Mettre à jour le rôle existant
                    $employe->adminUser->update([
                        'role'  => $request->role,
                        'email' => $request->email ?? $employe->adminUser->email,
                        'nom'   => trim($request->nom . ' ' . ($request->prenom ?? $employe->prenom ?? '')),
                    ]);
                    if ($request->filled('password')) {
                        $employe->adminUser->update(['password' => Hash::make($request->password)]);
                    }
                } else {
                    // Chercher un AdminUser existant avec cet email
                    $email = $request->email ?? $employe->email;
                    $existing = $email
                        ? AdminUser::where('email', $email)->where('tenant_id', $employe->tenant_id)->first()
                        : null;

                    if ($existing) {
                        $existing->update(['role' => $request->role]);
                        $employe->admin_user_id = $existing->id;
                    } else {
                        $adminUser = AdminUser::create([
                            'tenant_id' => $employe->tenant_id,
                            'nom'       => trim(($request->nom ?? $employe->nom) . ' ' . ($request->prenom ?? $employe->prenom ?? '')),
                            'email'     => $email,
                            'password'  => Hash::make($request->password ?? str()->random(12)),
                            'role'      => $request->role,
                            'actif'     => true,
                        ]);
                        $employe->admin_user_id = $adminUser->id;
                    }
                }
            }
        }

        $employe->save();

        return response()->json([
            'success' => true,
            'message' => 'Employé modifié avec succès',
            'employe' => $employe->load('adminUser:id,role,actif'),
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $employe = EmployeTenant::findOrFail($id);
        $employe->actif = false;
        $employe->save();

        return response()->json([
            'success' => true,
            'message' => 'Employé désactivé avec succès',
        ]);
    }

    public function uploadPhoto(Request $request, $id): JsonResponse
    {
        $employe = EmployeTenant::findOrFail($id);

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
            'success'   => true,
            'photo_url' => Storage::url($path),
        ]);
    }
}
