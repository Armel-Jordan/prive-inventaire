<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComClientEntete;
use App\Models\ReliquatClient;
use App\Services\DevisesVente;
use App\Services\ReliquatsClients;
use App\Support\TenantRule;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reliquats clients (back orders) : liste (avec le nombre par état), saisie à la main ou depuis une commande client,
 * commande au fournisseur, facturation des reliquats reçus. Les règles sont dans App\Services\ReliquatsClients.
 */
class ReliquatClientController extends Controller
{
    /** États « en cours » : pas encore facturés ni annulés. */
    private const OUVERTS = [ReliquatClient::CREE, ReliquatClient::COMMANDE, ReliquatClient::RECU_PARTIEL, ReliquatClient::RECU];

    public function __construct(private readonly ReliquatsClients $reliquats) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['statut' => ['nullable', Rule::in([...ReliquatClient::STATUTS, 'ouverts'])]]);
        $recherche = trim((string) $request->get('recherche', ''));

        $requete = ReliquatClient::query()
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('produit_id'), fn ($q) => $q->where('produit_id', $request->integer('produit_id')))
            ->when($request->filled('commande_client_id'), fn ($q) => $q->where('commande_client_id', $request->integer('commande_client_id')))
            ->when($recherche !== '', function ($q) use ($recherche) {
                $motif = '%'.addcslashes($recherche, '%_\\').'%';
                $q->where(fn ($r) => $r->where('notes', 'like', $motif)
                    ->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $motif)->orWhere('code', 'like', $motif))
                    ->orWhereHas('produit', fn ($p) => $p->where('numero', 'like', $motif)->orWhere('description', 'like', $motif))
                    ->orWhereHas('commandeClient', fn ($c) => $c->where('numero', 'like', $motif))
                    ->orWhereHas('ligneCommandeFournisseur.commande', fn ($c) => $c->where('numero', 'like', $motif))
                    ->orWhereHas('facture', fn ($f) => $f->where('numero', 'like', $motif)));
            });
        // Nombre par état de la sélection (hors filtre d'état), pour les onglets de l'écran.
        $resume = $this->reliquats->resume(clone $requete);
        $page = $requete
            ->when($request->get('statut') === 'ouverts', fn ($q) => $q->whereIn('statut', self::OUVERTS))
            ->when($request->filled('statut') && $request->get('statut') !== 'ouverts', fn ($q) => $q->where('statut', $request->get('statut')))
            ->with([
                'client:id,code,raison_sociale', 'produit:id,numero,description,mesure', 'commandeClient:id,numero',
                'ligneCommandeFournisseur:id,com_four_entete_id', 'ligneCommandeFournisseur.commande:id,numero,statut,fournisseur_id',
                'ligneCommandeFournisseur.commande.fournisseur:id,raison_sociale', 'facture:id,numero,statut',
            ])
            ->orderByRaw('CASE WHEN date_requise IS NULL THEN 1 ELSE 0 END')->orderBy('date_requise')->orderBy('id')
            ->paginate(min(100, max(1, (int) $request->get('per_page', 50))));

        return response()->json(['resume' => $resume] + $page->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'client_id' => ['required', 'integer', TenantRule::exists('clients')],
            'produit_id' => ['required', 'integer', TenantRule::exists('produits')->whereNull('deleted_at')],
            'quantite' => 'required|integer|min:1',
            'prix_unitaire_ht' => 'required|numeric|min:0',
            'remise_ligne' => 'nullable|numeric|min:0|max:100',
            ...DevisesVente::regles(),
            'date_requise' => 'nullable|date_format:Y-m-d',
            'notes' => 'nullable|string|max:255',
        ]);

        return response()->json($this->reliquats->creer($donnees, $request->user()?->id)->load(['client:id,code,raison_sociale', 'produit:id,numero,description']), 201);
    }

    /** Lignes d'une commande client, avec ce qui en est déjà en reliquat. */
    public function lignesCommande(int $commandeId): JsonResponse
    {
        $commande = ComClientEntete::with(['client:id,code,raison_sociale', 'lignes.produit' => fn ($q) => $q->withTrashed()->select('id', 'numero', 'description')])->findOrFail($commandeId);
        $deja = ReliquatClient::where('commande_client_id', $commande->id)->where('statut', '!=', ReliquatClient::ANNULE)
            ->selectRaw('commande_client_ligne_id, SUM(quantite) AS quantite')->groupBy('commande_client_ligne_id')->pluck('quantite', 'commande_client_ligne_id');

        return response()->json([
            'commande' => $commande->only(['id', 'numero', 'statut', 'client_id', 'devise']) + ['client' => $commande->client?->only(['id', 'code', 'raison_sociale'])],
            'possible' => in_array($commande->statut, ReliquatsClients::COMMANDES_SOURCES, true),
            'lignes' => $commande->lignes->map(fn ($l) => [
                'ligne_id' => $l->id,
                'produit' => $l->produit?->only(['id', 'numero', 'description']),
                'quantite' => (int) $l->quantite,
                'deja_en_reliquat' => (int) ($deja[$l->id] ?? 0),
                'prix_unitaire_ht' => (float) $l->prix_unitaire_ht,
            ])->values(),
        ]);
    }

    public function depuisCommande(Request $request, int $commandeId): JsonResponse
    {
        $commande = ComClientEntete::findOrFail($commandeId);
        $donnees = $request->validate([
            'lignes' => 'required|array|min:1',
            'lignes.*.ligne_id' => 'required|integer|distinct',
            'lignes.*.quantite' => 'required|integer|min:0',
        ]);
        $lignes = array_values(array_filter($donnees['lignes'], fn (array $l) => (int) $l['quantite'] > 0));
        if ($lignes === []) {
            return response()->json(['message' => 'Indiquez au moins une quantité en reliquat.', 'errors' => ['lignes' => ['Indiquez au moins une quantité en reliquat.']]], 422);
        }

        return $this->executer(fn () => response()->json($this->reliquats->depuisCommande($commande, $lignes, $request->user()?->id), 201));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $reliquat = ReliquatClient::findOrFail($id);
        $donnees = $request->validate([
            'quantite' => 'sometimes|integer|min:1',
            'prix_unitaire_ht' => 'sometimes|numeric|min:0',
            'remise_ligne' => 'sometimes|numeric|min:0|max:100',
            'date_requise' => 'nullable|date_format:Y-m-d',
            'notes' => 'nullable|string|max:255',
        ]);

        return $this->executer(fn () => response()->json($this->reliquats->modifier($reliquat, $donnees)));
    }

    public function annuler(int $id): JsonResponse
    {
        $reliquat = ReliquatClient::findOrFail($id);

        return $this->executer(fn () => response()->json($this->reliquats->annuler($reliquat)));
    }

    public function destroy(int $id): JsonResponse
    {
        $reliquat = ReliquatClient::findOrFail($id);

        return $this->executer(function () use ($reliquat) {
            $this->reliquats->supprimer($reliquat);

            return response()->json(['message' => 'Reliquat supprimé.']);
        });
    }

    /** Commandes fournisseur brouillons pour des reliquats inscrits. */
    public function commander(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'fournisseur_id' => ['nullable', 'integer', TenantRule::exists('fournisseurs')->where('actif', true)->whereNull('deleted_at')],
        ]);

        return $this->executer(fn () => response()->json($this->reliquats->commander(
            $donnees['ids'], $donnees['fournisseur_id'] ?? null, $request->attributes->get('tenant')->id, $request->user()->id,
        ), 201));
    }

    /** Factures brouillons des reliquats reçus. */
    public function facturer(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'date_facture' => 'required|date_format:Y-m-d',
        ]);

        return $this->executer(fn () => response()->json($this->reliquats->facturer(
            $donnees['ids'], $donnees['date_facture'], $request->attributes->get('tenant')->id,
        ), 201));
    }

    /** Erreurs de règle (état d'un reliquat, numérotation) : 422 avec leur message. */
    private function executer(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
