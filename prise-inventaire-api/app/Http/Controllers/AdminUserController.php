<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $users = AdminUser::where('tenant_id', $user->tenant_id)
            ->orderBy('nom')
            ->get(['id', 'nom', 'email', 'role', 'actif', 'derniere_connexion', 'created_at']);

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'nom' => 'required|string|max:100',
            'email' => 'required|email|unique:admin_users,email',
            'password' => 'required|string|min:6',
            'role' => 'required|in:admin,manager,user',
        ]);

        $newUser = AdminUser::create([
            'tenant_id' => $user->tenant_id,
            'nom' => $validated['nom'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'actif' => true,
        ]);

        return response()->json([
            'message' => 'Utilisateur créé avec succès',
            'user' => $newUser,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $currentUser = $request->user();
        $userToUpdate = AdminUser::where('tenant_id', $currentUser->tenant_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'nom' => 'sometimes|string|max:100',
            'email' => ['sometimes', 'email', Rule::unique('admin_users')->ignore($id)],
            'password' => 'sometimes|string|min:6',
            'role' => 'sometimes|in:admin,manager,user',
            'actif' => 'sometimes|boolean',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $userToUpdate->update($validated);

        return response()->json([
            'message' => 'Utilisateur mis à jour',
            'user' => $userToUpdate,
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $currentUser = $request->user();

        if ($currentUser->id == $id) {
            return response()->json(['message' => 'Vous ne pouvez pas supprimer votre propre compte'], 403);
        }

        $userToDelete = AdminUser::where('tenant_id', $currentUser->tenant_id)
            ->findOrFail($id);

        $userToDelete->delete();

        return response()->json(['message' => 'Utilisateur supprimé']);
    }
}
