<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Facture;
use App\Models\RetourClient;
use App\Models\RetourClientLigne;
use App\Services\RetoursClients;
use App\Support\TenantRule;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Retours clients : liste (avec le résumé par raison), détail, brouillons, traitement (remise en stock ou rebut) et
 * note de crédit d'un retour. Les règles sont dans App\Services\RetoursClients.
 */
class RetourClientController extends Controller
{
    public function __construct(private readonly RetoursClients $retours) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'statut' => ['nullable', Rule::in([RetourClient::BROUILLON, RetourClient::TRAITE])],
            'date_debut' => 'nullable|date_format:Y-m-d',
            'date_fin' => 'nullable|date_format:Y-m-d',
        ]);
        $recherche = trim((string) $request->get('recherche', ''));

        $requete = RetourClient::query()
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->statut))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('facture_id'), fn ($q) => $q->where('facture_id', $request->integer('facture_id')))
            ->when($request->filled('date_debut'), fn ($q) => $q->whereDate('date_retour', '>=', $request->date_debut))
            ->when($request->filled('date_fin'), fn ($q) => $q->whereDate('date_retour', '<=', $request->date_fin))
            ->when($recherche !== '', function ($q) use ($recherche) {
                $motif = '%'.addcslashes($recherche, '%_\\').'%';
                $q->where(fn ($r) => $r->where('numero', 'like', $motif)->orWhere('notes', 'like', $motif)
                    ->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $motif)->orWhere('code', 'like', $motif))
                    ->orWhereHas('facture', fn ($f) => $f->where('numero', 'like', $motif))
                    ->orWhereHas('lignes.produit', fn ($p) => $p->where('numero', 'like', $motif)->orWhere('description', 'like', $motif)));
            });

        // Résumé des retours traités de la sélection : quantités remises en stock, au rebut, et par raison.
        $resume = $this->retours->resume((clone $requete)->where('statut', RetourClient::TRAITE));
        $page = $requete->with(['client:id,code,raison_sociale', 'facture:id,numero', 'avoir:id,numero,statut'])
            ->withCount('lignes')->withSum('lignes as quantite_totale', 'quantite')
            ->orderByDesc('date_retour')->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->get('per_page', 20))));

        return response()->json(['resume' => $resume] + $page->toArray());
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(RetourClient::with([
            'client:id,code,raison_sociale', 'facture:id,numero,date_facture,statut', 'avoir:id,numero,statut,montant_ttc,devise',
            'lignes.produit' => fn ($q) => $q->withTrashed()->select('id', 'numero', 'description', 'mesure'),
            'lignes.raison:id,code,libelle', 'lignes.secteur:id,code,nom', 'auteur:id,nom', 'operateur:id,nom',
        ])->findOrFail($id));
    }

    /** Ce qu'on peut encore retourner d'une facture, pour préparer un retour. */
    public function proposition(int $factureId): JsonResponse
    {
        return response()->json($this->retours->proposition(Facture::findOrFail($factureId)));
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $this->valider($request, true);

        return $this->executer(fn () => response()->json(
            $this->retours->enregistrer($donnees, null, $request->user()?->id)->load('lignes'), 201,
        ));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $retour = RetourClient::findOrFail($id);
        $donnees = $this->valider($request, false);

        return $this->executer(fn () => response()->json(
            $this->retours->enregistrer($donnees, $retour, $request->user()?->id)->load('lignes'),
        ));
    }

    public function traiter(Request $request, int $id): JsonResponse
    {
        $retour = RetourClient::findOrFail($id);

        return $this->executer(fn () => response()->json($this->retours->traiter($retour, $request->user())->load('lignes')));
    }

    /** Note de crédit brouillon du retour, sur sa facture d'origine. */
    public function crediter(Request $request, int $id): JsonResponse
    {
        $retour = RetourClient::findOrFail($id);

        return $this->executer(fn () => response()->json($this->retours->crediter($retour, $request->user()?->id)->load('lignes'), 201));
    }

    public function destroy(int $id): JsonResponse
    {
        $retour = RetourClient::findOrFail($id);

        return $this->executer(function () use ($retour) {
            $this->retours->supprimer($retour);

            return response()->json(['message' => 'Retour supprimé.']);
        });
    }

    /** Lignes du retour : celles à quantité nulle sont ignorées (l'écran envoie toutes les lignes de la facture). */
    private function valider(Request $request, bool $creation): array
    {
        // L'application compte en UTC : un jour de marge laisse passer la date du jour de l'utilisateur, quel que soit son fuseau.
        $donnees = $request->validate([
            'client_id' => $creation ? ['required_without:facture_id', 'nullable', 'integer', TenantRule::exists('clients')] : 'prohibited',
            'facture_id' => $creation ? ['nullable', 'integer', TenantRule::exists('factures')] : 'prohibited',
            'date_retour' => 'required|date_format:Y-m-d|before_or_equal:tomorrow',
            'notes' => 'nullable|string|max:2000',
            'lignes' => 'required|array|min:1',
            'lignes.*.facture_ligne_id' => 'nullable|integer',
            'lignes.*.produit_id' => 'nullable|integer',
            'lignes.*.quantite' => 'required|integer|min:0',
            'lignes.*.raison_id' => ['nullable', 'integer', TenantRule::exists('raisons')->where('type', 'retour_client')->where('actif', true)->whereNull('deleted_at')],
            'lignes.*.traitement' => ['required', Rule::in(RetourClientLigne::TRAITEMENTS)],
            'lignes.*.secteur_id' => ['nullable', 'integer', TenantRule::exists('secteurs')->whereNull('deleted_at')],
            'lignes.*.notes' => 'nullable|string|max:255',
        ], [
            'date_retour.before_or_equal' => 'Le retour ne peut pas être daté dans le futur.',
            'lignes.*.raison_id.exists' => 'Choisissez une raison de retour active.',
        ]);
        $donnees['lignes'] = array_values(array_filter($donnees['lignes'], fn (array $l) => (int) $l['quantite'] > 0));
        if ($donnees['lignes'] === []) {
            abort(response()->json(['message' => 'Indiquez au moins une quantité retournée.', 'errors' => ['lignes' => ['Indiquez au moins une quantité retournée.']]], 422));
        }

        return $donnees;
    }

    /** Erreurs de règle (état du retour ou de la facture) : 422 avec leur message. */
    private function executer(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
