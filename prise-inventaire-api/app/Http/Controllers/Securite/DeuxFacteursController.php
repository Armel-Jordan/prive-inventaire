<?php

namespace App\Http\Controllers\Securite;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Services\DeuxFacteurs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Double authentification du compte connecté (activation, codes de secours, désactivation), et
 * réinitialisation par un administrateur pour un utilisateur qui a perdu son téléphone.
 */
class DeuxFacteursController extends Controller
{
    public function __construct(private DeuxFacteurs $deuxFacteurs) {}

    public function etat(Request $request): JsonResponse
    {
        return response()->json($this->etatDe($request->user()));
    }

    /** Nouveau secret à scanner ; le mot de passe est redemandé. */
    public function preparer(Request $request): JsonResponse
    {
        $utilisateur = $this->verifierMotDePasse($request);
        if ($utilisateur->deuxFacteursActive()) {
            return response()->json(['message' => 'La double authentification est déjà active.'], 422);
        }

        return response()->json($this->deuxFacteurs->preparer($utilisateur));
    }

    /** Confirme avec un premier code ; renvoie les codes de secours, affichés une seule fois. */
    public function activer(Request $request): JsonResponse
    {
        $utilisateur = $request->user();
        $donnees = $request->validate(['code' => ['required', 'string', 'max:20']]);
        if ($utilisateur->deuxFacteursActive()) {
            return response()->json(['message' => 'La double authentification est déjà active.'], 422);
        }

        $codes = $this->deuxFacteurs->activer($utilisateur, $donnees['code']);
        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'Code invalide : vérifiez l\'heure de votre téléphone et saisissez le code affiché.']);
        }

        return response()->json([...$this->etatDe($utilisateur->refresh()), 'codes_secours' => $codes]);
    }

    public function desactiver(Request $request): JsonResponse
    {
        $utilisateur = $this->verifierMotDePasse($request);
        $this->deuxFacteurs->desactiver($utilisateur);

        return response()->json($this->etatDe($utilisateur->refresh()));
    }

    public function regenererCodes(Request $request): JsonResponse
    {
        $utilisateur = $this->verifierMotDePasse($request);
        if (! $utilisateur->deuxFacteursActive()) {
            return response()->json(['message' => 'La double authentification n\'est pas active.'], 422);
        }
        $codes = $this->deuxFacteurs->regenererCodes($utilisateur);

        return response()->json([...$this->etatDe($utilisateur->refresh()), 'codes_secours' => $codes]);
    }

    /** Administrateurs : utilisateurs de l'entreprise et état de leur double authentification. */
    public function utilisateurs(Request $request): JsonResponse
    {
        $utilisateurs = AdminUser::where('tenant_id', $request->user()->tenant_id)
            ->orderBy('nom')
            ->get(['id', 'nom', 'email', 'role', 'actif', 'deux_facteurs_active_le', 'deux_facteurs_secret'])
            ->map(fn (AdminUser $u) => [
                'id' => $u->id, 'nom' => $u->nom, 'email' => $u->email, 'role' => $u->role, 'actif' => $u->actif,
                'deux_facteurs' => $u->deuxFacteursActive(),
                'deux_facteurs_active_le' => $u->deux_facteurs_active_le?->toIso8601String(),
            ]);

        return response()->json($utilisateurs);
    }

    /** Administrateurs : désactive la double authentification d'un utilisateur de l'entreprise. */
    public function reinitialiserUtilisateur(Request $request, int $id): JsonResponse
    {
        $utilisateur = AdminUser::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        $this->deuxFacteurs->desactiver($utilisateur);

        return response()->json(['message' => "Double authentification désactivée pour {$utilisateur->nom}."]);
    }

    private function etatDe(AdminUser $utilisateur): array
    {
        return [
            'active' => $utilisateur->deuxFacteursActive(),
            'active_le' => $utilisateur->deux_facteurs_active_le?->toIso8601String(),
            'codes_secours_restants' => $this->deuxFacteurs->codesRestants($utilisateur),
        ];
    }

    private function verifierMotDePasse(Request $request): AdminUser
    {
        $donnees = $request->validate(['password' => ['required', 'string']]);
        $utilisateur = $request->user();
        if (! Hash::check($donnees['password'], $utilisateur->password)) {
            throw ValidationException::withMessages(['password' => 'Mot de passe incorrect.']);
        }

        return $utilisateur;
    }
}
