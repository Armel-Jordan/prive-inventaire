<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PortailCompte;
use App\Models\PortailParametres;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Services\Portail\AccesPortail;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

/**
 * Portail client, côté accès : page d'accueil publique, connexion, choix du mot de passe depuis un lien
 * (invitation ou mot de passe oublié), profil et déconnexion. L'entreprise se désigne par son code (slug).
 */
class PortailAuthController extends Controller
{
    public function __construct(private AccesPortail $acces) {}

    /** Ce qu'affiche la page de connexion. 404 si l'entreprise n'a pas de portail ouvert. */
    public function infos(string $slug): JsonResponse
    {
        if (! $tenant = $this->tenantOuvert($slug)) {
            return $this->introuvable();
        }
        $parametres = TenantParametres::first();

        return response()->json([
            'entreprise' => $parametres?->nom_entreprise ?: $tenant->nom,
            'logo_url' => $parametres?->logo_url,
            'telephone' => $parametres?->telephone,
            'email' => $parametres?->email,
            'message_accueil' => PortailParametres::courants()->message_accueil,
        ]);
    }

    public function connexion(Request $request, string $slug): JsonResponse
    {
        $donnees = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:255']]);
        if (! $this->tenantOuvert($slug)) {
            return $this->introuvable();
        }
        $compte = PortailCompte::where('email', mb_strtolower(trim($donnees['email'])))->first();
        $valide = $compte && $compte->actif && $compte->estActive() && Hash::check($donnees['password'], $compte->password)
            && Client::where('id', $compte->client_id)->where('actif', true)->exists();
        if (! $valide) {
            return response()->json(['message' => __('Adresse ou mot de passe incorrect.')], 422);
        }

        return response()->json(['token' => $this->acces->ouvrirSession($compte), 'compte' => $this->presenter($compte)]);
    }

    /** Lien d'invitation ou de nouveau mot de passe : le client choisit son mot de passe et se trouve connecté. */
    public function activation(Request $request, string $slug): JsonResponse
    {
        $donnees = $request->validate([
            'jeton' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'confirmed', 'min:'.config('comptes.longueur_min_mot_de_passe'), 'max:255'],
        ], [
            'password.min' => __('Le mot de passe doit contenir au moins :min caractères.'),
            'password.confirmed' => __('La confirmation ne correspond pas au mot de passe.'),
        ]);
        $tenant = $this->tenantOuvert($slug);
        $compte = $tenant ? $this->acces->compteDuLien($donnees['jeton']) : null;
        if (! $compte || $compte->tenant_id !== $tenant->id || ! $compte->actif) {
            return response()->json(['message' => __("Ce lien n'est plus valable : demandez-en un nouveau.")], 422);
        }

        DB::transaction(function () use ($compte, $donnees) {
            $compte->forceFill(['password' => $donnees['password'], 'invitation_jeton' => null, 'invitation_expire_le' => null])->save();
            // Un nouveau mot de passe ferme les sessions ouvertes ailleurs.
            $compte->jetons()->delete();
        });

        return response()->json(['token' => $this->acces->ouvrirSession($compte), 'compte' => $this->presenter($compte->fresh())]);
    }

    /** Mot de passe oublié : la réponse est la même que le compte existe ou non. */
    public function oublie(Request $request, string $slug): JsonResponse
    {
        $donnees = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'langue' => ['nullable', Rule::in(['fr', 'en'])],
        ]);
        if (! $tenant = $this->tenantOuvert($slug)) {
            return $this->introuvable();
        }
        $compte = PortailCompte::where('email', mb_strtolower(trim($donnees['email'])))->where('actif', true)->first();
        if ($compte) {
            // Le lien pointe vers l'adresse connue de l'espace client, jamais vers une adresse envoyée ici.
            $lien = $this->acces->lien($tenant, $this->acces->nouvelleInvitation($compte));
            // Après la réponse : un temps de réponse plus long ne trahit pas l'existence du compte.
            defer(fn () => $this->acces->envoyer($compte, $tenant, $lien, $donnees['langue'] ?? 'fr', $compte->estActive()));
        }

        return response()->json(['message' => __('Si un compte correspond à cette adresse, un courriel avec un lien vient de vous être envoyé.')]);
    }

    public function deconnexion(Request $request): JsonResponse
    {
        $request->attributes->get('portail_jeton')->delete();

        return response()->json(['message' => __('Vous êtes déconnecté.')]);
    }

    /** Compte connecté, son client (adresses de livraison) et l'entreprise. */
    public function moi(Request $request): JsonResponse
    {
        $compte = $request->attributes->get('portail_compte');
        $client = Client::with(['adresses' => fn ($q) => $q->where('actif', true)->orderByDesc('principale')->orderBy('libelle')])->findOrFail($compte->client_id);
        $parametres = TenantParametres::first();
        $tenant = $request->attributes->get('tenant');

        return response()->json([
            'compte' => $this->presenter($compte),
            'client' => $client->only(['id', 'code', 'raison_sociale', 'email', 'telephone', 'adresse_facturation', 'code_postal', 'ville']) + [
                'adresses' => $client->adresses->map(fn ($a) => $a->only(['id', 'libelle', 'adresse', 'code_postal', 'ville', 'pays', 'livraison', 'principale']))->values(),
            ],
            'entreprise' => [
                'nom' => $parametres?->nom_entreprise ?: $tenant->nom,
                'devise' => $parametres?->devise_symbole,
                'telephone' => $parametres?->telephone,
                'email' => $parametres?->email,
            ],
        ]);
    }

    public function modifierProfil(Request $request): JsonResponse
    {
        $compte = $request->attributes->get('portail_compte');
        $donnees = $request->validate(['nom' => ['required', 'string', 'max:150'], 'telephone' => ['nullable', 'string', 'max:50']]);
        $compte->update(['nom' => trim($donnees['nom']), 'telephone' => $donnees['telephone'] ?? null]);

        return response()->json(['compte' => $this->presenter($compte->fresh())]);
    }

    /** Mot de passe actuel exigé ; les autres sessions sont fermées. */
    public function changerMotDePasse(Request $request): JsonResponse
    {
        $compte = $request->attributes->get('portail_compte');
        $donnees = $request->validate([
            'mot_de_passe_actuel' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'different:mot_de_passe_actuel', 'min:'.config('comptes.longueur_min_mot_de_passe'), 'max:255'],
        ], [
            'password.min' => __('Le mot de passe doit contenir au moins :min caractères.'),
            'password.confirmed' => __('La confirmation ne correspond pas au mot de passe.'),
            'password.different' => __("Le nouveau mot de passe doit être différent de l'actuel."),
        ]);
        if (! Hash::check($donnees['mot_de_passe_actuel'], $compte->password)) {
            throw ValidationException::withMessages(['mot_de_passe_actuel' => __('Mot de passe actuel incorrect.')]);
        }
        $compte->forceFill(['password' => $donnees['password']])->save();
        $compte->jetons()->whereKeyNot($request->attributes->get('portail_jeton')->id)->delete();

        return response()->json(['message' => __('Mot de passe modifié. Vos autres sessions ont été fermées.')]);
    }

    /** Entreprise active, avec le module Ventes et un portail ouvert ; fixe son contexte. */
    private function tenantOuvert(string $slug): ?Tenant
    {
        $tenant = Tenant::where('slug', mb_strtolower(trim($slug)))->where('actif', true)->first();
        if (! $tenant || $tenant->isExpired() || ! $tenant->hasModule('ventes')) {
            return null;
        }
        app(TenantContext::class)->setTenantId((int) $tenant->id);

        return PortailParametres::courants()->actif ? $tenant : null;
    }

    private function introuvable(): JsonResponse
    {
        return response()->json(['message' => __("Ce portail client n'existe pas ou n'est pas ouvert.")], 404);
    }

    private function presenter(PortailCompte $compte): array
    {
        return $compte->only(['id', 'nom', 'email', 'telephone', 'client_id']);
    }
}
