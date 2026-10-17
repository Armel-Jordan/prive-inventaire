<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Acompte;
use App\Services\Acomptes;
use App\Services\DevisesVente;
use App\Support\TenantRule;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Acomptes des clients : liste (tous clients confondus, avec ce qui reste de chacun), détail, saisie, application au
 * paiement d'une facture et remboursement. Les règles sont dans App\Services\Acomptes.
 */
class AcompteController extends Controller
{
    /** Ce qui reste d'un acompte, en SQL. */
    private const DISPONIBLE = '(montant - montant_applique - montant_rembourse)';

    public function __construct(private readonly Acomptes $acomptes) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'disponibilite' => ['nullable', Rule::in(['avec_solde', 'utilise'])],
            'date_debut' => 'nullable|date_format:Y-m-d',
            'date_fin' => 'nullable|date_format:Y-m-d',
        ]);
        $recherche = trim((string) $request->get('recherche', ''));

        $requete = Acompte::query()
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('commande_id'), fn ($q) => $q->where('commande_id', $request->integer('commande_id')))
            ->when($request->get('disponibilite') === 'avec_solde', fn ($q) => $q->whereRaw(self::DISPONIBLE.' > 0.004'))
            ->when($request->get('disponibilite') === 'utilise', fn ($q) => $q->whereRaw(self::DISPONIBLE.' <= 0.004'))
            ->when($request->filled('date_debut'), fn ($q) => $q->whereDate('date_acompte', '>=', $request->date_debut))
            ->when($request->filled('date_fin'), fn ($q) => $q->whereDate('date_acompte', '<=', $request->date_fin))
            ->when($recherche !== '', function ($q) use ($recherche) {
                $motif = '%'.addcslashes($recherche, '%_\\').'%';
                $q->where(fn ($r) => $r->where('reference', 'like', $motif)->orWhere('notes', 'like', $motif)
                    ->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $motif)->orWhere('code', 'like', $motif))
                    ->when(is_numeric(str_replace(',', '.', $recherche)), fn ($m) => $m->orWhere('montant', (float) str_replace(',', '.', $recherche))));
            });
        // Requête de base (portée de l'entreprise comprise) : sur un modèle, l'accesseur « disponible » masquerait la somme.
        // Totaux dans la devise de l'entreprise, chaque acompte à son taux.
        $totaux = (clone $requete)->toBase()->selectRaw('COALESCE(SUM(montant * taux_change), 0) AS montant, COALESCE(SUM('.self::DISPONIBLE.' * taux_change), 0) AS disponible')->first();
        $page = $requete->with(['client:id,code,raison_sociale', 'commande:id,numero', 'demande:id,numero'])
            ->orderByDesc('date_acompte')->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->get('per_page', 20))));

        return response()->json($page->toArray() + ['totaux' => [
            'montant' => round((float) $totaux->montant, 2),
            'disponible' => round((float) $totaux->disponible, 2),
        ]]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(Acompte::with([
            'client:id,code,raison_sociale', 'commande:id,numero,statut', 'demande:id,numero',
            'applications.facture:id,numero', 'remboursements', 'auteur:id,nom',
        ])->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'client_id' => ['required', 'integer', TenantRule::exists('clients')],
            'date_acompte' => 'required|date_format:Y-m-d',
            'mode' => ['required', Rule::in(Acomptes::MODES)],
            'reference' => 'nullable|string|max:100',
            'montant' => 'required|numeric|min:0.01|max:9999999999',
            'commande_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:2000',
            ...DevisesVente::regles(),
            // Caisse du poste : l'acompte entre dans sa session ouverte (refusé si elle est fermée).
            'caisse_id' => 'nullable|integer',
        ]);

        return $this->executer(fn () => response()->json($this->acomptes->recevoir($donnees, $request->user()?->id)->load('client:id,code,raison_sociale', 'commande:id,numero'), 201));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $acompte = Acompte::findOrFail($id);
        $donnees = $request->validate([
            'date_acompte' => 'sometimes|date_format:Y-m-d',
            'mode' => ['sometimes', Rule::in(Acomptes::MODES)],
            'reference' => 'sometimes|nullable|string|max:100',
            'montant' => 'sometimes|numeric|min:0.01|max:9999999999',
            'commande_id' => 'sometimes|nullable|integer',
            'notes' => 'sometimes|nullable|string|max:2000',
        ]);

        return $this->executer(fn () => response()->json($this->acomptes->modifier($acompte, $donnees)->load('client:id,code,raison_sociale', 'commande:id,numero')));
    }

    public function destroy(int $id): JsonResponse
    {
        $acompte = Acompte::findOrFail($id);

        return $this->executer(function () use ($acompte) {
            $this->acomptes->supprimer($acompte);

            return response()->json(['message' => 'Acompte supprimé.']);
        });
    }

    /** Applique une part de l'acompte au paiement d'une facture due du même client. */
    public function appliquer(Request $request, int $id): JsonResponse
    {
        $acompte = Acompte::findOrFail($id);
        $donnees = $request->validate([
            'facture_id' => 'required|integer',
            'montant' => 'required|numeric|min:0.01',
            'date' => 'required|date_format:Y-m-d',
        ]);

        return $this->executer(fn () => response()->json($this->acomptes->appliquer(
            $acompte, (int) $donnees['facture_id'], (float) $donnees['montant'], $donnees['date'], $request->user()?->id,
        )->load('applications.facture:id,numero')));
    }

    public function rembourser(Request $request, int $id): JsonResponse
    {
        $acompte = Acompte::findOrFail($id);
        $donnees = $request->validate([
            'montant' => 'required|numeric|min:0.01',
            'date_remboursement' => 'required|date_format:Y-m-d',
            'mode' => ['nullable', Rule::in(Acomptes::MODES)],
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:2000',
            // Caisse du poste : le remboursement sort de sa session ouverte (refusé si elle est fermée).
            'caisse_id' => 'nullable|integer',
        ]);

        return $this->executer(fn () => response()->json($this->acomptes->rembourser($acompte, $donnees, $request->user()?->id)->load('remboursements')));
    }

    /** Erreurs de règle (état de l'acompte) : 422 avec leur message. */
    private function executer(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
