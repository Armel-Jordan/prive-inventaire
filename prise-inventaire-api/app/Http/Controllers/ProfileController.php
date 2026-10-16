<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\Configuration;
use App\Models\EmployeTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        // Simple consultation : la fiche n'est plus créée à l'ouverture de « Mon profil » (une fiche
        // employé apparaissait à chaque visite d'un administrateur). Elle l'est au premier enregistrement.
        return response()->json([
            'employe' => $this->ficheExistante($request->user()) ?? $this->nouvelleFiche($request->user()),
        ]);
    }

    /** Fiche employé de l'utilisateur, rattachée par son courriel. */
    private function ficheExistante(AdminUser $user): ?EmployeTenant
    {
        return EmployeTenant::where('email', $user->email)->first();
    }

    /** Fiche pré-remplie depuis le compte, non enregistrée. */
    private function nouvelleFiche(AdminUser $user): EmployeTenant
    {
        $parties = explode(' ', trim($user->nom ?: 'Utilisateur'));
        $prenom = $parties[0];
        $nom = count($parties) > 1 ? implode(' ', array_slice($parties, 1)) : $prenom;

        return new EmployeTenant(['nom' => $nom, 'prenom' => $prenom, 'email' => $user->email, 'actif' => true]);
    }

    /** Fiche existante, ou créée au premier enregistrement du profil (numéro de la numérotation des employés). */
    private function ficheOuCreation(AdminUser $user): EmployeTenant
    {
        return $this->ficheExistante($user) ?? DB::transaction(function () use ($user) {
            $fiche = $this->nouvelleFiche($user);
            $fiche->numero = $this->numeroEmploye((int) $user->tenant_id);
            $fiche->save();

            return $fiche;
        });
    }

    private function numeroEmploye(int $tenantId): string
    {
        $config = Configuration::pourEntite('employe', $tenantId);
        if ($config && $config->auto_increment) {
            return Configuration::consommerNumero('employe', $tenantId);
        }

        // Sans numérotation automatique : suite EMP001, EMP002… comme auparavant.
        $dernier = EmployeTenant::orderByDesc('id')->first();
        $suivant = $dernier ? ((int) filter_var($dernier->numero, FILTER_SANITIZE_NUMBER_INT) + 1) : 1;

        return 'EMP'.str_pad((string) $suivant, 3, '0', STR_PAD_LEFT);
    }

    public function update(Request $request): JsonResponse
    {
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

        $employe = $this->ficheOuCreation($request->user());
        $employe->fill($request->only([
            'telephone', 'adresse', 'ville', 'code_postal', 'pays',
            'sexe', 'date_naissance', 'poste', 'departement',
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
        $request->validate([
            'photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $employe = $this->ficheOuCreation($request->user());

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
