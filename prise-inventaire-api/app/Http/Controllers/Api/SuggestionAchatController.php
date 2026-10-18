<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Facture;
use App\Models\SuggestionAchat;
use App\Services\DisponibiliteProduits;
use App\Services\ReliquatsClients;
use App\Services\SuggestionsAchat;
use App\Support\Droits;
use App\Support\TenantRule;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Suggestions d'achat : liste (avec le disponible, ce qui est en commande, le vendu sur 12 mois et le dernier coûtant),
 * calcul, suggestion manuelle, acceptation, modification, suppression et commandes fournisseur des suggestions
 * acceptées. Les règles sont dans App\Services\SuggestionsAchat.
 */
class SuggestionAchatController extends Controller
{
    public function __construct(private readonly SuggestionsAchat $suggestions) {}

    /** Suggestions ouvertes (en attente et acceptées), ou commandées, par fournisseur puis produit. */
    public function index(Request $request, DisponibiliteProduits $disponibilites, ReliquatsClients $reliquats): JsonResponse
    {
        $request->validate(['statut' => ['nullable', Rule::in(['ouvertes', 'commandees'])]]);
        $suggestions = SuggestionAchat::with([
            'produit:id,numero,description,mesure,seuil_alerte,stock_maximum,unite_achat,qte_par_unite_achat',
            'fournisseur:id,code,raison_sociale,devise',
            'reliquat:id,client_id,commande_client_id,quantite,date_requise,statut',
            'reliquat.client:id,code,raison_sociale',
            'reliquat.commandeClient:id,numero',
            'ligneCommande:id,com_four_entete_id',
            'ligneCommande.commande:id,numero,statut',
        ])
            ->when($request->get('statut') === 'commandees',
                fn ($q) => $q->where('statut', SuggestionAchat::COMMANDEE)->where('updated_at', '>=', now()->subDays(30)),
                fn ($q) => $q->whereIn('statut', [SuggestionAchat::EN_ATTENTE, SuggestionAchat::ACCEPTEE]))
            ->orderBy('fournisseur_id')->orderBy('produit_id')->orderBy('id')->limit(500)->get();

        $produits = $suggestions->pluck('produit_id')->unique()->values()->all();
        $disponible = $disponibilites->calculer($produits)->keyBy('produit_id');
        $vendu = $this->venduSur12Mois($produits);

        return response()->json($suggestions->map(fn (SuggestionAchat $s) => $s->toArray() + [
            'disponible' => $disponible[$s->produit_id]['disponible'] ?? null,
            'en_commande' => $disponible[$s->produit_id]['en_commande'] ?? null,
            'vendu_12_mois' => (int) ($vendu[$s->produit_id] ?? 0),
            'dernier_cout' => $s->fournisseur_id ? $reliquats->dernierPrix($s->produit_id, $s->fournisseur_id) : null,
        ])->values());
    }

    /** Calcule les suggestions (inventaire, reliquats clients) ; les acceptées et les manuelles restent. */
    public function generer(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'inventaire' => 'boolean',
            'reliquats' => 'boolean',
            'avec_commandes' => 'boolean',
            'fournisseur_id' => ['nullable', 'integer', TenantRule::exists('fournisseurs')],
            'produit_id' => ['nullable', 'integer', TenantRule::exists('produits')],
        ]);
        $nombre = $this->suggestions->generer(
            (bool) ($donnees['inventaire'] ?? true), (bool) ($donnees['reliquats'] ?? true), (bool) ($donnees['avec_commandes'] ?? true),
            $donnees['fournisseur_id'] ?? null, $donnees['produit_id'] ?? null,
        );

        return response()->json(['message' => __(':nombre suggestion(s) proposée(s).', ['nombre' => $nombre]), 'nombre' => $nombre]);
    }

    /** Besoin signalé depuis un produit (suggestion manuelle, en attente). */
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'produit_id' => ['required', 'integer', TenantRule::exists('produits')],
            'quantite' => 'required|integer|min:1|max:999999',
            'remarque' => 'nullable|string|max:255',
            'forcer' => 'boolean',
        ]);

        return response()->json($this->suggestions->suggerer(
            (int) $donnees['produit_id'], (int) $donnees['quantite'], $donnees['remarque'] ?? null, (bool) ($donnees['forcer'] ?? false), $request->user(),
        )->load('produit:id,numero,description'), 201);
    }

    /** Quantité à commander, fournisseur et remarque d'une suggestion ouverte. */
    public function update(Request $request, int $id): JsonResponse
    {
        $suggestion = SuggestionAchat::findOrFail($id);
        $donnees = $request->validate([
            'quantite' => 'sometimes|integer|min:1|max:999999',
            'fournisseur_id' => ['sometimes', 'integer', TenantRule::exists('fournisseurs')],
            'remarque' => 'sometimes|nullable|string|max:255',
        ]);
        if ($suggestion->statut === SuggestionAchat::COMMANDEE) {
            return response()->json(['message' => __('Cette suggestion est déjà commandée.')], 422);
        }
        $suggestion->update($donnees);

        return response()->json($suggestion->fresh(['produit:id,numero,description', 'fournisseur:id,code,raison_sociale']));
    }

    /** Accepte (ou remet en attente) des suggestions. */
    public function accepter(Request $request): JsonResponse
    {
        $donnees = $request->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer', 'accepte' => 'required|boolean']);
        $nombre = $this->suggestions->accepter($donnees['ids'], (bool) $donnees['accepte'], $request->user());

        return response()->json(['nombre' => $nombre]);
    }

    public function destroy(int $id): JsonResponse
    {
        $suggestion = SuggestionAchat::findOrFail($id);
        if ($suggestion->statut === SuggestionAchat::COMMANDEE) {
            return response()->json(['message' => __('Cette suggestion est déjà commandée.')], 422);
        }
        $suggestion->delete();

        return response()->json(['message' => __('Suggestion retirée.')]);
    }

    /** Commandes fournisseur des suggestions acceptées : il faut aussi le droit de créer des commandes fournisseur. */
    public function commander(Request $request, Droits $droits): JsonResponse
    {
        $donnees = $request->validate(['fusionner' => 'boolean']);
        if (! $droits->permet($request->user(), 'commandes_fournisseur', 'creer')) {
            return response()->json(['message' => $droits->messageRefus('commandes_fournisseur', 'creer'), 'module' => 'commandes_fournisseur', 'action' => 'creer'], 403);
        }
        try {
            $commandes = $this->suggestions->commander((bool) ($donnees['fusionner'] ?? false), $request->user()->tenant_id, $request->user());
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => __(':nombre commande(s) fournisseur en préparation : :numeros.', [
                'nombre' => $commandes->count(), 'numeros' => $commandes->pluck('numero')->implode(', '),
            ]),
            'commandes' => $commandes->map(fn ($c) => $c->only(['id', 'numero', 'fournisseur_id', 'montant_total']))->values(),
        ], 201);
    }

    /** Quantités facturées sur les 12 derniers mois (factures émises, payées ou créditées), par produit. */
    private function venduSur12Mois(array $produits): array
    {
        if ($produits === []) {
            return [];
        }
        $depuis = today()->subYear()->toDateString();
        $ventes = DB::table('facture_lignes')->join('factures', 'factures.id', '=', 'facture_lignes.facture_id')
            ->whereIn('factures.id', Facture::whereIn('statut', ['emise', 'partiellement_payee', 'payee', 'creditee'])->whereDate('date_facture', '>=', $depuis)->select('id'))
            ->whereIn('facture_lignes.produit_id', $produits)
            ->groupBy('facture_lignes.produit_id')->selectRaw('facture_lignes.produit_id AS produit, SUM(facture_lignes.quantite) AS quantite')
            ->pluck('quantite', 'produit');

        return $ventes->map(fn ($q) => (int) $q)->all();
    }
}
