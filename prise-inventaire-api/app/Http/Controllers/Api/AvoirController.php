<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Avoir;
use App\Models\Facture;
use App\Services\NotesCredit;
use App\Services\TaxesDocument;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Notes de crédit (avoirs) : liste, détail, brouillons, émission, imputation sur une facture et remboursement.
 * Les règles sont dans App\Services\NotesCredit.
 */
class AvoirController extends Controller
{
    public function __construct(private readonly NotesCredit $notes, private readonly TaxesDocument $taxes) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'statut' => ['nullable', Rule::in([Avoir::BROUILLON, Avoir::EMIS, Avoir::SOLDE])],
            'date_debut' => 'nullable|date_format:Y-m-d',
            'date_fin' => 'nullable|date_format:Y-m-d',
        ]);
        $recherche = trim((string) $request->get('recherche', ''));

        $avoirs = Avoir::with(['client:id,code,raison_sociale', 'facture:id,numero'])
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->statut))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('facture_id'), fn ($q) => $q->where('facture_id', $request->integer('facture_id')))
            ->when($request->filled('date_debut'), fn ($q) => $q->whereDate('date_avoir', '>=', $request->date_debut))
            ->when($request->filled('date_fin'), fn ($q) => $q->whereDate('date_avoir', '<=', $request->date_fin))
            ->when($recherche !== '', function ($q) use ($recherche) {
                $motif = '%'.addcslashes($recherche, '%_\\').'%';
                $q->where(fn ($r) => $r->where('numero', 'like', $motif)->orWhere('motif', 'like', $motif)
                    ->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $motif)->orWhere('code', 'like', $motif))
                    ->orWhereHas('facture', fn ($f) => $f->where('numero', 'like', $motif)));
            })
            ->orderByDesc('date_avoir')->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->get('per_page', 20))));

        return response()->json($avoirs);
    }

    public function show(int $id): JsonResponse
    {
        $avoir = Avoir::with([
            'client:id,code,raison_sociale,email', 'facture:id,numero,date_facture,statut,montant_ttc,montant_paye,montant_credite,reste_a_payer',
            'lignes.produit' => fn ($q) => $q->withTrashed()->select('id', 'numero', 'description', 'mesure'),
            'imputations.facture:id,numero', 'remboursements', 'auteur:id,nom', 'emetteur:id,nom', 'retour:id,avoir_id,numero',
        ])->findOrFail($id);
        // Détail par taxe, comme sur la facture (vide si ses lignes utilisent un taux unique).
        $avoir->setAttribute('recapitulatif_taxes', $this->taxes->recapitulatif('avoir', $avoir->lignes->pluck('id')));

        return response()->json($avoir);
    }

    /** Ce qu'on peut encore créditer sur une facture, pour préparer une note de crédit. */
    public function proposition(int $factureId): JsonResponse
    {
        return response()->json($this->notes->proposition(Facture::findOrFail($factureId)));
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $this->valider($request, true);
        $facture = Facture::findOrFail($donnees['facture_id']);

        return $this->executer(fn () => response()->json(
            $this->notes->enregistrer($facture, $donnees, null, $request->user()?->id)->load('lignes'), 201,
        ));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $avoir = Avoir::findOrFail($id);
        $donnees = $this->valider($request, false);

        return $this->executer(fn () => response()->json(
            $this->notes->enregistrer($avoir->facture, $donnees, $avoir, $request->user()?->id)->load('lignes'),
        ));
    }

    public function emettre(Request $request, int $id): JsonResponse
    {
        $avoir = Avoir::findOrFail($id);

        return $this->executer(fn () => response()->json($this->notes->emettre($avoir, $request->user()?->id)));
    }

    /** Factures dues du client sur lesquelles imputer le crédit disponible. */
    public function facturesImputables(int $id): JsonResponse
    {
        $avoir = Avoir::findOrFail($id);

        return response()->json(Facture::where('client_id', $avoir->client_id)
            ->whereIn('statut', NotesCredit::FACTURES_DUES)->where('reste_a_payer', '>', 0)
            ->orderBy('date_facture')->orderBy('id')
            ->get(['id', 'numero', 'date_facture', 'date_echeance', 'montant_ttc', 'reste_a_payer']));
    }

    public function imputer(Request $request, int $id): JsonResponse
    {
        $avoir = Avoir::findOrFail($id);
        $donnees = $request->validate([
            'facture_id' => 'required|integer',
            'montant' => 'required|numeric|min:0.01',
            'date' => 'required|date_format:Y-m-d',
        ]);

        return $this->executer(fn () => response()->json($this->notes->imputer(
            $avoir, (int) $donnees['facture_id'], (float) $donnees['montant'], $donnees['date'], $request->user()?->id,
        )->load('imputations.facture:id,numero')));
    }

    public function rembourser(Request $request, int $id): JsonResponse
    {
        $avoir = Avoir::findOrFail($id);
        $donnees = $request->validate([
            'montant' => 'required|numeric|min:0.01',
            'date_remboursement' => 'required|date_format:Y-m-d',
            'mode' => ['required', Rule::in(NotesCredit::MODES_REMBOURSEMENT)],
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:2000',
        ]);

        return $this->executer(fn () => response()->json($this->notes->rembourser($avoir, $donnees, $request->user()?->id)->load('remboursements')));
    }

    public function destroy(int $id): JsonResponse
    {
        $avoir = Avoir::findOrFail($id);

        return $this->executer(function () use ($avoir) {
            $this->notes->supprimer($avoir);

            return response()->json(['message' => __('Note de crédit supprimée.')]);
        });
    }

    /** Lignes à créditer : celles à quantité nulle sont ignorées (l'écran envoie toutes les lignes de la facture). */
    private function valider(Request $request, bool $creation): array
    {
        $donnees = $request->validate([
            'facture_id' => $creation ? 'required|integer' : 'prohibited',
            'date_avoir' => 'required|date_format:Y-m-d',
            'motif' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'lignes' => 'required|array|min:1',
            'lignes.*.facture_ligne_id' => 'required|integer|distinct',
            'lignes.*.quantite' => 'required|integer|min:0',
            'lignes.*.prix_unitaire_ht' => 'nullable|numeric|min:0',
        ]);
        $donnees['lignes'] = array_values(array_filter($donnees['lignes'], fn (array $l) => (int) $l['quantite'] > 0));
        if ($donnees['lignes'] === []) {
            abort(response()->json(['message' => __('Indiquez au moins une quantité à créditer.'), 'errors' => ['lignes' => [__('Indiquez au moins une quantité à créditer.')]]], 422));
        }

        return $donnees;
    }

    /** Erreurs de règle (état de la note ou de la facture) : 422 avec leur message. */
    private function executer(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
