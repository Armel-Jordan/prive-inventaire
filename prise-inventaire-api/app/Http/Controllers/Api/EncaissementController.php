<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Encaissement;
use App\Services\DevisesVente;
use App\Services\EncaissementPdf;
use App\Services\Encaissements;
use App\Support\TenantRule;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Encaissements des clients : liste, factures dues d'un client, saisie (répartition sur les factures, surplus en
 * acompte, escompte), correction, annulation et reçu. Les règles sont dans App\Services\Encaissements.
 */
class EncaissementController extends Controller
{
    public function __construct(private readonly Encaissements $encaissements) {}

    /** Liste filtrable (recherche, client, mode, statut, période), avec les totaux des encaissements valides. */
    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'recherche' => 'nullable|string|max:100',
            'client_id' => 'nullable|integer',
            'mode' => ['nullable', Rule::in(Encaissement::MODES)],
            'statut' => ['nullable', Rule::in([Encaissement::VALIDE, Encaissement::ANNULE])],
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date',
            'per_page' => 'nullable|integer|between:1,100',
        ]);
        $recherche = trim($filtres['recherche'] ?? '');
        $requete = Encaissement::query()
            ->when($recherche !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('numero', 'like', "%{$recherche}%")
                ->orWhere('reference', 'like', "%{$recherche}%")
                ->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', "%{$recherche}%")->orWhere('code', 'like', "%{$recherche}%"))
                ->orWhereHas('lignes.facture', fn ($f) => $f->where('numero', 'like', "%{$recherche}%"))))
            ->when($filtres['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($filtres['mode'] ?? null, fn ($q, $mode) => $q->where('mode', $mode))
            ->when($filtres['date_debut'] ?? null, fn ($q, $date) => $q->whereDate('date_encaissement', '>=', $date))
            ->when($filtres['date_fin'] ?? null, fn ($q, $date) => $q->whereDate('date_encaissement', '<=', $date));
        $resume = $this->encaissements->resume(clone $requete);
        $page = $requete->when($filtres['statut'] ?? null, fn ($q, $statut) => $q->where('statut', $statut))
            ->with(['client:id,code,raison_sociale', 'lignes.facture:id,numero'])
            ->orderByDesc('date_encaissement')->orderByDesc('id')
            ->paginate($filtres['per_page'] ?? 20);

        return response()->json(['resume' => $resume] + $page->toArray());
    }

    /** Factures dues d'un client dans une devise (vide : la sienne) et crédits dont il dispose. */
    public function aRegler(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'client_id' => ['required', TenantRule::exists('clients')],
            'devise' => DevisesVente::regles()['devise'],
        ]);

        return response()->json($this->encaissements->aRegler(Client::findOrFail($donnees['client_id']), $donnees['devise'] ?? null));
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'client_id' => ['required', TenantRule::exists('clients')],
            'date_encaissement' => 'required|date|before_or_equal:tomorrow',
            'mode' => ['required', Rule::in(Encaissement::MODES)],
            'reference' => 'nullable|string|max:100',
            'montant' => 'required|numeric|gt:0|max:9999999999',
            ...DevisesVente::regles(),
            'notes' => 'nullable|string|max:2000',
            // Accorder en escompte ce que la répartition dépasse du montant reçu.
            'escompte' => 'sometimes|boolean',
            // Caisse du poste : l'encaissement entre dans sa session ouverte (refusé si elle est fermée).
            'caisse_id' => 'nullable|integer',
            // Compte bancaire par lequel passe l'argent (chèque, virement, carte).
            'compte_bancaire_id' => 'nullable|integer',
            'lignes' => 'nullable|array|max:200',
            'lignes.*.facture_id' => 'required|integer',
            'lignes.*.montant' => 'required|numeric|min:0',
        ]);

        return $this->executer(fn () => response()->json(
            $this->encaissements->enregistrer($donnees, $request->attributes->get('tenant')->id, $request->user()?->id), 201,
        ));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(Encaissement::query()->with($this->encaissements->relations())->findOrFail($id));
    }

    /** Corrige la référence et les notes. */
    public function update(Request $request, int $id): JsonResponse
    {
        $encaissement = Encaissement::findOrFail($id);
        $donnees = $request->validate([
            'reference' => 'sometimes|nullable|string|max:100',
            'notes' => 'sometimes|nullable|string|max:2000',
        ]);

        return $this->executer(fn () => response()->json($this->encaissements->modifier($encaissement, $donnees)));
    }

    public function annuler(Request $request, int $id): JsonResponse
    {
        $encaissement = Encaissement::findOrFail($id);
        $donnees = $request->validate(['motif' => 'nullable|string|max:255']);

        return $this->executer(fn () => response()->json($this->encaissements->annuler($encaissement, $donnees['motif'] ?? null, $request->user()?->id)));
    }

    /** Reçu de l'encaissement (preuve de paiement remise au client). */
    public function pdf(int $id, EncaissementPdf $pdf): Response
    {
        $encaissement = Encaissement::findOrFail($id);

        return $pdf->pdf($encaissement)->stream("recu-{$encaissement->numero}.pdf");
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
