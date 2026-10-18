<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Configuration;
use App\Models\PortailCompte;
use App\Models\PortailParametres;
use App\Models\ProduitTenant;
use App\Services\Portail\AccesPortail;
use App\Support\TenantContext;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Espace client, côté entreprise : ouverture et réglages, accès des clients (invitation par courriel, lien à
 * transmettre si le courriel ne part pas, suspension, suppression). Le back-office transmet l'adresse du site de
 * l'espace client (« origine »), retenue pour les liens des courriels. Lecture pour le module Ventes ;
 * modifications par les administrateurs et responsables (routes).
 */
class PortailClientController extends Controller
{
    public function __construct(private AccesPortail $acces) {}

    public function index(): JsonResponse
    {
        $parametres = PortailParametres::courants();
        $comptes = PortailCompte::with('client:id,code,raison_sociale')->orderBy('nom')->get()
            ->map(fn (PortailCompte $c) => $this->presenter($c));

        return response()->json([
            'parametres' => ['actif' => $parametres->actif, 'message_accueil' => $parametres->message_accueil, 'categories' => $parametres->categories],
            'comptes' => $comptes,
            'categories_disponibles' => ProduitTenant::where('actif', true)->whereNotNull('categorie')->where('categorie', '!=', '')
                ->distinct()->orderBy('categorie')->pluck('categorie')->values(),
            // Sans numérotation automatique des commandes client, le portail refuse les commandes : l'écran le signale.
            'commandes_pretes' => (bool) Configuration::pourEntite('commande', (int) app(TenantContext::class)->getTenantId())?->auto_increment,
        ]);
    }

    public function parametres(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'actif' => ['required', 'boolean'],
            'message_accueil' => ['nullable', 'string', 'max:2000'],
            'categories' => ['nullable', 'array', 'max:500'],
            'categories.*' => ['string', 'max:100'],
            'origine' => ['nullable', 'string', 'max:255'],
        ]);
        $parametres = PortailParametres::courants();
        $parametres->fill([
            'actif' => $donnees['actif'],
            'message_accueil' => trim((string) ($donnees['message_accueil'] ?? '')) ?: null,
            // Aucune catégorie choisie : tous les produits actifs.
            'categories' => array_values(array_unique($donnees['categories'] ?? [])) ?: null,
            'url_espace_client' => AccesPortail::normaliserAdresse($donnees['origine'] ?? null) ?? $parametres->url_espace_client,
        ])->save();

        return response()->json(['message' => $parametres->actif ? __('Portail ouvert.') : __('Portail fermé.')]);
    }

    /** Nouvel accès : le contact reçoit un lien pour choisir son mot de passe (le lien est aussi renvoyé ici). */
    public function store(Request $request): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $donnees = $request->validate([
            'client_id' => ['required', 'integer', TenantRule::exists('clients')],
            'nom' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', TenantRule::unique('portail_comptes', 'email')],
            'telephone' => ['nullable', 'string', 'max:50'],
            'origine' => ['nullable', 'string', 'max:255'],
            'langue' => ['nullable', Rule::in(['fr', 'en'])],
        ], ['email.unique' => __('Cette adresse a déjà un accès au portail.')]);
        $compte = PortailCompte::create([
            'client_id' => $donnees['client_id'],
            'nom' => trim($donnees['nom']),
            'email' => $donnees['email'],
            'telephone' => $donnees['telephone'] ?? null,
            'actif' => true,
            'cree_par' => $request->user()?->id,
        ]);

        return response()->json($this->inviter($request, $compte, $donnees), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $compte = PortailCompte::findOrFail($id);
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', TenantRule::unique('portail_comptes', 'email', $compte->id)],
            'telephone' => ['nullable', 'string', 'max:50'],
            'actif' => ['required', 'boolean'],
        ], ['email.unique' => __('Cette adresse a déjà un accès au portail.')]);
        $compte->update(['nom' => trim($donnees['nom'])] + $donnees);
        // Accès suspendu : ses sessions sont fermées.
        if (! $compte->actif) {
            $compte->jetons()->delete();
        }

        return response()->json($this->presenter($compte->fresh('client:id,code,raison_sociale')));
    }

    /** Nouveau lien (invitation non reçue, ou mot de passe à changer) : le précédent ne vaut plus. */
    public function invitation(Request $request, int $id): JsonResponse
    {
        $donnees = $request->validate(['origine' => ['nullable', 'string', 'max:255'], 'langue' => ['nullable', Rule::in(['fr', 'en'])]]);

        return response()->json($this->inviter($request, PortailCompte::findOrFail($id), $donnees));
    }

    public function destroy(int $id): JsonResponse
    {
        PortailCompte::findOrFail($id)->delete();

        return response()->json(['message' => __('Accès supprimé.')]);
    }

    private function inviter(Request $request, PortailCompte $compte, array $donnees): array
    {
        $tenant = $request->attributes->get('tenant');
        $this->acces->memoriserAdresse($donnees['origine'] ?? null);
        $lien = $this->acces->lien($tenant, $this->acces->nouvelleInvitation($compte));
        $envoye = $this->acces->envoyer($compte, $tenant, $lien, $donnees['langue'] ?? 'fr', $compte->estActive());

        return [
            'compte' => $this->presenter($compte->fresh('client:id,code,raison_sociale')),
            'lien' => $lien,
            'courriel_envoye' => $envoye,
            'message' => $envoye
                ? __('Lien envoyé à :email.', ['email' => $compte->email])
                : __("Le courriel n'a pas pu partir : transmettez ce lien au client."),
        ];
    }

    private function presenter(PortailCompte $compte): array
    {
        return $compte->only(['id', 'client_id', 'nom', 'email', 'telephone', 'actif']) + [
            'client' => $compte->client?->only(['id', 'code', 'raison_sociale']),
            // invitee : lien envoyé, mot de passe pas encore choisi.
            'etat' => ! $compte->actif ? 'suspendu' : ($compte->estActive() ? 'actif' : 'invite'),
            'invitation_expire_le' => $compte->invitation_expire_le?->toIso8601String(),
            'derniere_connexion' => $compte->derniere_connexion?->toIso8601String(),
        ];
    }
}
