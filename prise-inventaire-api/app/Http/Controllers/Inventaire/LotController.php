<?php

namespace App\Http\Controllers\Inventaire;

use App\Http\Controllers\Controller;
use App\Models\Lot;
use App\Models\LotMouvement;
use App\Models\TachePlanifiee;
use App\Services\LotsSeries;
use App\Support\FichierCsv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Registre des lots (phase 2.8) : numéro, produit, péremption, quantités reçues et solde (journal du lot, en unités
 * de stock), état de la péremption ; détail d'un lot et de ses mouvements ; correction de la péremption ou des notes.
 */
class LotController extends Controller
{
    public const ETATS = ['perimes', 'a_surveiller', 'sans_date'];

    public function index(Request $request): JsonResponse|Response
    {
        $filtres = $request->validate([
            'recherche' => ['nullable', 'string', 'max:100'],
            'produit_id' => ['nullable', 'integer'],
            'etat' => ['nullable', Rule::in(self::ETATS)],
            'avec_stock' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $jours = $this->jours();
        $requete = $this->requete($filtres, $jours);

        if ($request->query('format') === 'csv') {
            $lignes = $requete->get()->map(fn (Lot $l) => $this->presenter($l, $jours))->map(fn (array $l) => [
                $l['produit']['numero'] ?? '', $l['produit']['description'] ?? '', $l['numero'], $l['date_peremption'] ?? '',
                $l['fournisseur'] ?? '', (float) $l['recu'], (float) $l['solde'], $l['etat'] ?? '',
            ])->all();

            return FichierCsv::reponse('lots.csv', ['produit', 'description', 'lot', 'peremption', 'fournisseur', 'recu', 'solde', 'etat'], $lignes);
        }

        $page = $requete->paginate(50, ['*'], 'page', (int) ($filtres['page'] ?? 1));
        $page->getCollection()->transform(fn (Lot $l) => $this->presenter($l, $jours));

        return response()->json($page->toArray() + ['jours_alerte' => $jours]);
    }

    public function show(int $id): JsonResponse
    {
        $lot = $this->requete([], $this->jours())->with(['mouvements.receptionLigne' => fn ($q) => $q->with(['reception:id,numero,date_reception',
            'ligneCommande:id,com_four_entete_id', 'ligneCommande.commande:id,numero', 'secteur:id,code,nom']),
            'mouvements.client:id,code,raison_sociale', 'mouvements.bonLivraisonLigne.bonLivraison:id,numero', 'mouvements.factureLigne.facture:id,numero'])->findOrFail($id);

        return response()->json($this->presenter($lot, $this->jours()) + [
            'notes' => $lot->notes,
            'mouvements' => $lot->mouvements->map(fn (LotMouvement $m) => [
                'id' => $m->id,
                'type' => $m->type,
                'quantite' => $m->quantite,
                'date' => $m->date_mouvement?->toDateString(),
                'reception' => $m->receptionLigne?->reception?->only(['id', 'numero']),
                'commande' => $m->receptionLigne?->ligneCommande?->commande?->numero,
                'secteur' => $m->receptionLigne?->secteur?->code,
                // Sortie : le bon de livraison ou la facture de la vente au comptoir, et le client.
                'document' => $m->bonLivraisonLigne?->bonLivraison?->numero ?? $m->factureLigne?->facture?->numero,
                'client' => $m->client ? "{$m->client->code} — {$m->client->raison_sociale}" : null,
            ])->values(),
        ]);
    }

    /** Rappel d'un lot : les clients qui l'ont reçu, avec le document, la date, la quantité et leurs coordonnées ; CSV. */
    public function rappel(Request $request, int $id, LotsSeries $lots): JsonResponse|Response
    {
        $lot = Lot::query()->with('produit:id,numero,description,mesure')->findOrFail($id);
        $sorties = $lots->rappel($lot);
        if ($request->query('format') === 'csv') {
            return FichierCsv::reponse("rappel-lot-{$lot->numero}.csv", ['date', 'document', 'client', 'nom', 'telephone', 'courriel', 'quantite'],
                array_map(fn (array $s) => [$s['date'], $s['document'], $s['client']['code'] ?? '', $s['client']['raison_sociale'] ?? '',
                    $s['client']['telephone'] ?? '', $s['client']['email'] ?? '', (float) $s['quantite']], $sorties));
        }

        return response()->json(['lot' => $this->presenter($lot->loadSum('journal as solde', 'quantite')
            ->loadSum(['journal as recu' => fn ($q) => $q->where('type', LotMouvement::RECEPTION)], 'quantite'), $this->jours()), 'sorties' => $sorties]);
    }

    /** Correction de la péremption ou des notes d'un lot. */
    public function update(Request $request, int $id): JsonResponse
    {
        $lot = Lot::query()->findOrFail($id);
        $lot->update($request->validate([
            'date_peremption' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]));

        return $this->show($lot->id);
    }

    /** Préavis des lots « à surveiller » : le réglage de la tâche des péremptions. */
    private function jours(): int
    {
        return max(0, (int) TachePlanifiee::parametreDe('peremptions', 'jours'));
    }

    private function requete(array $filtres, int $jours): Builder
    {
        $solde = '(SELECT COALESCE(SUM(m.quantite), 0) FROM lot_mouvements m WHERE m.lot_id = lots.id)';
        $motif = filled($filtres['recherche'] ?? null) ? '%'.addcslashes(trim((string) $filtres['recherche']), '%_\\').'%' : null;

        return Lot::query()
            ->with(['produit:id,numero,description,mesure,peremption', 'fournisseur:id,raison_sociale'])
            ->withSum('journal as solde', 'quantite')
            ->withSum(['journal as recu' => fn ($q) => $q->where('type', LotMouvement::RECEPTION)], 'quantite')
            ->withMin(['journal as premiere_reception' => fn ($q) => $q->where('type', LotMouvement::RECEPTION)], 'date_mouvement')
            ->when($motif, fn (Builder $q) => $q->where(fn (Builder $r) => $r->where('numero', 'like', $motif)
                ->orWhereHas('produit', fn (Builder $p) => $p->where('numero', 'like', $motif)->orWhere('description', 'like', $motif))))
            ->when($filtres['produit_id'] ?? null, fn (Builder $q, $produit) => $q->where('produit_id', $produit))
            ->when(($filtres['etat'] ?? null) === 'perimes', fn (Builder $q) => $q->whereDate('date_peremption', '<', today()->toDateString()))
            ->when(($filtres['etat'] ?? null) === 'a_surveiller', fn (Builder $q) => $q->whereDate('date_peremption', '>=', today()->toDateString())
                ->whereDate('date_peremption', '<=', today()->addDays($jours)->toDateString()))
            ->when(($filtres['etat'] ?? null) === 'sans_date', fn (Builder $q) => $q->whereNull('date_peremption'))
            ->when(! empty($filtres['avec_stock']), fn (Builder $q) => $q->whereRaw("{$solde} > 0"))
            // La péremption la plus proche d'abord ; les lots sans date ensuite.
            ->orderByRaw('CASE WHEN date_peremption IS NULL THEN 1 ELSE 0 END')
            ->orderBy('date_peremption')->orderBy('numero');
    }

    /** Un lot présenté : produit, quantités, état de la péremption (périmé, à surveiller dans le préavis, sinon rien). */
    private function presenter(Lot $lot, int $jours): array
    {
        $restants = $lot->date_peremption ? (int) today()->diffInDays($lot->date_peremption, false) : null;

        return [
            'id' => $lot->id,
            'numero' => $lot->numero,
            'produit' => $lot->produit?->only(['id', 'numero', 'description', 'mesure']),
            'date_peremption' => $lot->date_peremption?->toDateString(),
            'jours_restants' => $restants,
            'etat' => $restants === null ? null : ($restants < 0 ? 'perime' : ($restants <= $jours ? 'a_surveiller' : 'ok')),
            'fournisseur' => $lot->fournisseur?->raison_sociale,
            'recu' => round((float) $lot->recu, 4),
            'solde' => round((float) $lot->solde, 4),
            'premiere_reception' => $lot->premiere_reception ? substr((string) $lot->premiere_reception, 0, 10) : null,
        ];
    }
}
