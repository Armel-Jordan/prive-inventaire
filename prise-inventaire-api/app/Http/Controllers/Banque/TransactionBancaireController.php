<?php

namespace App\Http\Controllers\Banque;

use App\Http\Controllers\Controller;
use App\Models\TransactionBancaire;
use App\Services\Banque\TransactionsBancaires;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Transactions bancaires sans facture : liste, détail, saisie (dépôt, retrait, virement), annulation. */
class TransactionBancaireController extends Controller
{
    public function __construct(private readonly TransactionsBancaires $transactions) {}

    /** Liste filtrable (compte bancaire, type, statut, période, recherche). */
    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'compte_bancaire_id' => 'nullable|integer',
            'type' => ['nullable', Rule::in(TransactionBancaire::TYPES)],
            'statut' => ['nullable', Rule::in([TransactionBancaire::VALIDE, TransactionBancaire::ANNULE])],
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date',
            'recherche' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|between:1,100',
        ]);
        $recherche = trim($filtres['recherche'] ?? '');
        $page = TransactionBancaire::query()
            // Un virement figure sur les deux comptes.
            ->when($filtres['compte_bancaire_id'] ?? null, fn ($q, $id) => $q->where(fn ($q) => $q->where('compte_bancaire_id', $id)->orWhere('compte_bancaire_destination_id', $id)))
            ->when($filtres['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filtres['statut'] ?? null, fn ($q, $statut) => $q->where('statut', $statut))
            ->when($filtres['date_debut'] ?? null, fn ($q, $date) => $q->whereDate('date_transaction', '>=', $date))
            ->when($filtres['date_fin'] ?? null, fn ($q, $date) => $q->whereDate('date_transaction', '<=', $date))
            ->when($recherche !== '', fn ($q) => $q->where(fn ($q) => $q->where('numero', 'like', "%{$recherche}%")
                ->orWhere('reference', 'like', "%{$recherche}%")->orWhere('libelle', 'like', "%{$recherche}%")))
            ->with(['compteBancaire:id,nom,devise_code', 'destination:id,nom,devise_code'])
            ->orderByDesc('date_transaction')->orderByDesc('id')
            ->paginate($filtres['per_page'] ?? 20);

        return response()->json($page);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(TransactionBancaire::query()->with($this->transactions->relations())->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'compte_bancaire_id' => 'required|integer',
            'date_transaction' => 'required|date|before_or_equal:tomorrow',
            'type' => ['required', Rule::in(TransactionBancaire::TYPES)],
            'compte_bancaire_destination_id' => 'nullable|integer|required_if:type,virement',
            'reference' => 'nullable|string|max:100',
            'libelle' => 'required|string|max:255',
            'montant' => 'nullable|numeric|max:9999999999',
            'taux_change' => 'nullable|numeric|gt:0',
            'notes' => 'nullable|string|max:2000',
            'lignes' => 'nullable|array|max:100',
            'lignes.*.compte_comptable_id' => 'required|integer',
            'lignes.*.libelle' => 'nullable|string|max:255',
            'lignes.*.montant' => 'required|numeric|between:-9999999999,9999999999',
        ]);

        return $this->executer(fn () => response()->json(
            $this->transactions->enregistrer($donnees, $request->attributes->get('tenant')->id, $request->user()?->id), 201,
        ));
    }

    public function annuler(Request $request, int $id): JsonResponse
    {
        $transaction = TransactionBancaire::query()->findOrFail($id);
        $donnees = $request->validate(['motif' => 'nullable|string|max:255']);

        return $this->executer(fn () => response()->json($this->transactions->annuler($transaction, $donnees['motif'] ?? null, $request->user()?->id)));
    }

    private function executer(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
