<?php

namespace App\Http\Controllers;

use App\Models\EmployeTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        // Trouver l'employé associé à l'utilisateur
        $employe = EmployeTenant::where('email', $user->email)->first();

        // Si pas d'employé, en créer un automatiquement
        if (!$employe) {
            // Générer un numéro unique
            $lastEmploye = EmployeTenant::orderByDesc('id')->first();
            $nextNum = $lastEmploye ? ((int) filter_var($lastEmploye->numero, FILTER_SANITIZE_NUMBER_INT) + 1) : 1;
            $numero = 'EMP' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

            // Extraire nom/prénom de l'utilisateur
            $nameParts = explode(' ', $user->nom ?? $user->name ?? 'Utilisateur');
            $prenom = $nameParts[0] ?? '';
            $nom = isset($nameParts[1]) ? implode(' ', array_slice($nameParts, 1)) : $prenom;

            $employe = EmployeTenant::create([
                'numero' => $numero,
                'nom' => $nom ?: 'Utilisateur',
                'prenom' => $prenom,
                'email' => $user->email,
                'actif' => true,
            ]);
        }

        return response()->json([
            'employe' => $employe,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $employe = EmployeTenant::where('email', $user->email)->first();

        if (!$employe) {
            return response()->json([
                'message' => 'Profil employé non trouvé',
            ], 404);
        }

        $request->validate([
            'telephone' => 'nullable|string|max:20',
            'adresse' => 'nullable|string|max:255',
            'ville' => 'nullable|string|max:100',
            'code_postal' => 'nullable|string|max:20',
            'pays' => 'nullable|string|max:100',
            'sexe' => 'nullable|in:M,F,autre',
            'date_naissance' => 'nullable|date',
            'poste' => 'nullable|string|max:100',
            'departement' => 'nullable|string|max:100',
        ]);

        $employe->fill($request->only([
            'telephone', 'adresse', 'ville', 'code_postal', 'pays',
            'sexe', 'date_naissance', 'poste', 'departement'
        ]));
        $employe->save();

        return response()->json([
            'success' => true,
            'message' => 'Profil mis à jour avec succès',
            'employe' => $employe,
        ]);
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        $user = $request->user();

        $employe = EmployeTenant::where('email', $user->email)->first();

        if (!$employe) {
            return response()->json([
                'message' => 'Profil employé non trouvé',
            ], 404);
        }

        $request->validate([
            'photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        // Supprimer l'ancienne photo si elle existe
        if ($employe->photo && Storage::disk('public')->exists($employe->photo)) {
            Storage::disk('public')->delete($employe->photo);
        }

        // Sauvegarder la nouvelle photo
        $path = $request->file('photo')->store('photos/employes', 'public');
        $employe->photo = $path;
        $employe->save();

        return response()->json([
            'success' => true,
            'message' => 'Photo mise à jour avec succès',
            'photo_url' => Storage::url($path),
        ]);
    }
}
