<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Consignation;
use App\Models\ConsignationMouvement;
use App\Models\ProduitTenant;
use App\Services\Consignations;
use App\Services\ConsignationsPdf;
use App\Support\Droits;
use App\Support\TenantRule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Consignation chez le client : bons de dépôt, relevé (solde par produit), reprise, facturation de la consommation,
 * historique, rapport des consignations chez les clients.
 */
class ConsignationController extends Controller
{
    public function __construct(private readonly Consignations $consignations) {}

    /** Bons de dépôt, les plus récents d'abord. */
    public function index(Request $request): JsonResponse
    {
        $requete = Consignation::query()->with('client:id,code,raison_sociale')->withCount('lignes')->withSum('lignes', 'quantite')
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')));
        if ($request->filled('recherche')) {
            $texte = '%'.addcslashes(trim((string) $request->input('recherche')), '%_\\').'%';
            $requete->where(fn ($q) => $q->where('numero', 'like', $texte)->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $texte)));
        }

        return response()->json($requete->orderByDesc('date_depot')->orderByDesc('id')->paginate(min(100, max(1, $request->integer('per_page', 20)))));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(Consignation::with(['client:id,code,raison_sociale', 'lignes.produit:id,numero,description'])->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'client_id' => ['required', 'integer', TenantRule::exists('clients')->whereNull('deleted_at')],
            'date_depot' => ['required', 'date', 'before_or_equal:tomorrow'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lignes' => ['required', 'array', 'min:1', 'max:200'],
            'lignes.*.produit_id' => ['required', 'integer', TenantRule::exists('produits')->whereNull('deleted_at')],
            'lignes.*.quantite' => ['required', 'integer', 'min:1', 'max:999999'],
        ], ['lignes.required' => 'Ajoutez au moins un produit déposé.']);
        $consignation = $this->consignations->deposer($donnees, $request->user()?->id, $request->user()->tenant_id);

        return $this->show($consignation->id)->setStatusCode(201);
    }

    public function pdf(int $id, ConsignationsPdf $documents): Response
    {
        $consignation = Consignation::findOrFail($id);

        return $documents->bonDeDepot($consignation)->stream("consignation-{$consignation->numero}.pdf");
    }

    /** Relevé d'un client : ce qu'il détient, produit par produit, avec la valeur au coût. */
    public function releve(Request $request): JsonResponse
    {
        $clientId = (int) $request->validate(['client_id' => ['required', 'integer', TenantRule::exists('clients')]])['client_id'];
        $soldes = $this->consignations->soldes($clientId);
        $produits = ProduitTenant::withTrashed()->whereKey($soldes->pluck('produit_id'))->get(['id', 'numero', 'description'])->keyBy('id');
        $lignes = $soldes->map(fn (array $s) => $s + ['produit' => $produits[$s['produit_id']]?->only(['id', 'numero', 'description'])])
            ->sortBy(fn ($s) => $s['produit']['numero'] ?? '')->values();

        return response()->json([
            'client' => Client::withTrashed()->find($clientId)?->only(['id', 'code', 'raison_sociale', 'devise']),
            'lignes' => $lignes,
            'quantite' => (int) $lignes->sum('quantite'),
            'valeur' => round($lignes->sum(fn ($l) => $l['valeur'] ?? 0), 2),
        ]);
    }

    /** Reprise : le client rend de la marchandise, qui revient au lieu choisi. */
    public function reprise(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'client_id' => ['required', 'integer', TenantRule::exists('clients')],
            'lieu_retour' => ['required', 'string', 'max:100'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'integer'],
            'lignes.*.quantite' => ['required', 'integer', 'min:0', 'max:999999'],
        ], ['lieu_retour.required' => 'Choisissez le lieu où la marchandise revient.']);
        $unites = $this->consignations->reprendre($donnees['client_id'], $donnees['lignes'], trim($donnees['lieu_retour']), $request->user()?->id);

        return response()->json(['message' => "{$unites} unité(s) reprise(s)."]);
    }

    /** Consommation : facture brouillon aux prix du client (créer une facture, et l'émettre si demandé, sont exigés). */
    public function facturer(Request $request, Droits $droits): JsonResponse
    {
        $donnees = $request->validate([
            'client_id' => ['required', 'integer', TenantRule::exists('clients')],
            'emettre' => ['boolean'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'integer'],
            'lignes.*.quantite' => ['required', 'integer', 'min:0', 'max:999999'],
        ]);
        $emettre = (bool) ($donnees['emettre'] ?? false);
        foreach (array_filter(['creer' => true, 'emettre' => $emettre]) as $action => $requis) {
            if (! $droits->permet($request->user(), 'factures', $action)) {
                return response()->json(['message' => $droits->messageRefus('factures', $action), 'module' => 'factures', 'action' => $action], 403);
            }
        }
        $facture = $this->consignations->facturer($donnees['client_id'], $donnees['lignes'], $emettre, $request->user()?->id, $request->user()->tenant_id);

        return response()->json([
            'message' => "Facture {$facture->numero} créée ; le solde consigné est déduit.",
            'facture' => $facture->fresh()->only(['id', 'numero', 'statut', 'montant_ttc', 'devise']),
        ], 201);
    }

    /** Historique d'un client : dépôts, retours et consommations sur une période (12 derniers mois par défaut). */
    public function historique(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'client_id' => ['required', 'integer', TenantRule::exists('clients')],
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date', 'after_or_equal:du'],
        ], ['au.after_or_equal' => 'La date de début est postérieure à la date de fin : corrigez la période.']);
        $du = isset($donnees['du']) ? Carbon::parse($donnees['du']) : today()->subYear();
        $au = isset($donnees['au']) ? Carbon::parse($donnees['au']) : today();

        return response()->json($this->consignations->historique((int) $donnees['client_id'], $du, $au)
            ->map(fn (ConsignationMouvement $m) => $m->only(['id', 'type', 'quantite', 'date_mouvement', 'lieu_retour', 'note'])
                + ['produit' => $m->produit?->only(['id', 'numero', 'description']), 'consignation' => $m->consignation?->only(['id', 'numero']),
                    'facture' => $m->facture?->only(['id', 'numero'])]));
    }

    /** Consignations chez les clients : tous (ou un seul), produits, valeur au coût, sous-totaux. */
    public function rapport(Request $request, ConsignationsPdf $documents): JsonResponse|Response
    {
        $donnees = $request->validate(['client_id' => ['nullable', 'integer', TenantRule::exists('clients')], 'nuls' => ['boolean'], 'format' => ['nullable', 'in:json,pdf']]);
        $rapport = $documents->rapport($donnees['client_id'] ?? null, (bool) ($donnees['nuls'] ?? false));
        if (($donnees['format'] ?? 'json') === 'pdf') {
            return $documents->rapportPdf($rapport)->stream('consignations-chez-les-clients.pdf');
        }

        return response()->json($rapport);
    }
}
