<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComFourEntete;
use App\Models\RetourClient;
use App\Models\RetourFournisseur;
use App\Services\DevisesVente;
use App\Services\RetourFournisseurPdf;
use App\Services\RetoursFournisseurs;
use App\Support\TenantRule;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Retours fournisseurs : liste (avec le résumé des crédits), détail, brouillons, expédition, clôture, PDF du bon de
 * retour. Les règles sont dans App\Services\RetoursFournisseurs.
 */
class RetourFournisseurController extends Controller
{
    public function __construct(private readonly RetoursFournisseurs $retours) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'statut' => ['nullable', Rule::in([RetourFournisseur::BROUILLON, RetourFournisseur::EXPEDIE, RetourFournisseur::CLOS])],
            'type' => ['nullable', Rule::in(RetourFournisseur::TYPES)],
            'date_debut' => 'nullable|date_format:Y-m-d',
            'date_fin' => 'nullable|date_format:Y-m-d',
        ]);
        $recherche = trim((string) $request->get('recherche', ''));

        $requete = RetourFournisseur::query()
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->statut))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('fournisseur_id'), fn ($q) => $q->where('fournisseur_id', $request->integer('fournisseur_id')))
            ->when($request->filled('commande_fournisseur_id'), fn ($q) => $q->where('commande_fournisseur_id', $request->integer('commande_fournisseur_id')))
            ->when($request->filled('retour_client_id'), fn ($q) => $q->where('retour_client_id', $request->integer('retour_client_id')))
            ->when($request->filled('date_debut'), fn ($q) => $q->whereDate('date_retour', '>=', $request->date_debut))
            ->when($request->filled('date_fin'), fn ($q) => $q->whereDate('date_retour', '<=', $request->date_fin))
            ->when($recherche !== '', function ($q) use ($recherche) {
                $motif = '%'.addcslashes($recherche, '%_\\').'%';
                $q->where(fn ($r) => $r->where('numero', 'like', $motif)->orWhere('numero_rma', 'like', $motif)
                    ->orWhere('reference_avoir', 'like', $motif)->orWhere('notes', 'like', $motif)
                    ->orWhereHas('fournisseur', fn ($f) => $f->where('raison_sociale', 'like', $motif)->orWhere('code', 'like', $motif))
                    ->orWhereHas('commande', fn ($c) => $c->where('numero', 'like', $motif))
                    ->orWhereHas('retourClient', fn ($c) => $c->where('numero', 'like', $motif))
                    ->orWhereHas('lignes.produit', fn ($p) => $p->where('numero', 'like', $motif)->orWhere('description', 'like', $motif)));
            });

        // Résumé de la sélection, dans la devise de l'entreprise : crédits attendus et obtenus, retours en cours.
        $resume = $this->retours->resume(clone $requete);
        $page = $requete->with(['fournisseur:id,code,raison_sociale', 'commande:id,numero', 'retourClient:id,numero'])
            ->withCount('lignes')->withSum('lignes as quantite_totale', 'quantite')
            ->orderByDesc('date_retour')->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->get('per_page', 20))));

        return response()->json(['resume' => $resume] + $page->toArray());
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(RetourFournisseur::with([
            'fournisseur:id,code,raison_sociale,email,devise', 'commande:id,numero,date_commande,statut', 'retourClient:id,numero,client_id',
            'retourClient.client:id,raison_sociale', 'secteurRemplacement:id,code,nom',
            'lignes.produit' => fn ($q) => $q->withTrashed()->select('id', 'numero', 'description', 'mesure'),
            'lignes.raison:id,code,libelle', 'lignes.secteur:id,code,nom', 'auteur:id,nom', 'expediteur:id,nom', 'operateur:id,nom',
        ])->findOrFail($id));
    }

    /** Ce qui peut repartir d'une commande fournisseur. */
    public function depuisCommande(int $commandeId): JsonResponse
    {
        return response()->json($this->retours->propositionCommande(ComFourEntete::findOrFail($commandeId)));
    }

    /** Ce qu'un retour client traité peut renvoyer au fournisseur. */
    public function depuisRetourClient(int $retourId): JsonResponse
    {
        return response()->json($this->retours->propositionRetourClient(RetourClient::findOrFail($retourId)));
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
        $retour = RetourFournisseur::findOrFail($id);
        $donnees = $this->valider($request, false);

        return $this->executer(fn () => response()->json(
            $this->retours->enregistrer($donnees, $retour, $request->user()?->id)->load('lignes'),
        ));
    }

    public function expedier(Request $request, int $id): JsonResponse
    {
        $retour = RetourFournisseur::findOrFail($id);
        // L'application compte en UTC : un jour de marge laisse passer la date du jour de l'utilisateur, quel que soit son fuseau.
        $donnees = $request->validate(['date_expedition' => 'nullable|date_format:Y-m-d|before_or_equal:tomorrow'], [
            'date_expedition.before_or_equal' => "L'expédition ne peut pas être datée dans le futur.",
        ]);

        return $this->executer(fn () => response()->json(
            $this->retours->expedier($retour, $request->user(), $donnees['date_expedition'] ?? null)->load('lignes'),
        ));
    }

    public function cloturer(Request $request, int $id): JsonResponse
    {
        $retour = RetourFournisseur::findOrFail($id);
        $donnees = $request->validate([
            'date_cloture' => 'required|date_format:Y-m-d|before_or_equal:tomorrow',
            'montant_credite' => 'nullable|numeric|min:0',
            'reference_avoir' => 'nullable|string|max:50',
            'secteur_remplacement_id' => ['nullable', 'integer', TenantRule::exists('secteurs')->whereNull('deleted_at')],
        ], [
            'date_cloture.before_or_equal' => 'La clôture ne peut pas être datée dans le futur.',
        ]);

        return $this->executer(fn () => response()->json($this->retours->cloturer($retour, $donnees, $request->user())->load('lignes')));
    }

    public function pdf(int $id, RetourFournisseurPdf $pdf): Response
    {
        $retour = RetourFournisseur::findOrFail($id);

        return $pdf->pdf($retour)->stream('retour-fournisseur-'.($retour->numero ?? "brouillon-{$retour->id}").'.pdf');
    }

    public function destroy(int $id): JsonResponse
    {
        $retour = RetourFournisseur::findOrFail($id);

        return $this->executer(function () use ($retour) {
            $this->retours->supprimer($retour);

            return response()->json(['message' => 'Retour supprimé.']);
        });
    }

    /** Lignes du retour : celles à quantité nulle sont ignorées (l'écran envoie toutes les lignes de l'origine). */
    private function valider(Request $request, bool $creation): array
    {
        // L'application compte en UTC : un jour de marge laisse passer la date du jour de l'utilisateur, quel que soit son fuseau.
        $donnees = $request->validate([
            'fournisseur_id' => ['nullable', 'integer', TenantRule::exists('fournisseurs')->where('actif', true)->whereNull('deleted_at')],
            'commande_fournisseur_id' => $creation ? ['nullable', 'integer', 'prohibits:retour_client_id', TenantRule::exists('com_four_entete')] : 'prohibited',
            'retour_client_id' => $creation ? ['nullable', 'integer', TenantRule::exists('retours_clients')] : 'prohibited',
            'type' => ['required', Rule::in(RetourFournisseur::TYPES)],
            'date_retour' => 'required|date_format:Y-m-d|before_or_equal:tomorrow',
            'numero_rma' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:2000',
            ...DevisesVente::regles(),
            'lignes' => 'required|array|min:1',
            'lignes.*.com_four_ligne_id' => 'nullable|integer',
            'lignes.*.retour_client_ligne_id' => 'nullable|integer',
            'lignes.*.produit_id' => 'nullable|integer',
            'lignes.*.quantite' => 'required|integer|min:0',
            'lignes.*.prix_unitaire' => 'nullable|numeric|min:0',
            'lignes.*.raison_id' => ['nullable', 'integer', TenantRule::exists('raisons')->where('type', 'retour_fournisseur')->where('actif', true)->whereNull('deleted_at')],
            'lignes.*.secteur_id' => ['nullable', 'integer', TenantRule::exists('secteurs')->whereNull('deleted_at')],
            'lignes.*.notes' => 'nullable|string|max:255',
        ], [
            'fournisseur_id.exists' => 'Choisissez un fournisseur actif.',
            'date_retour.before_or_equal' => 'Le retour ne peut pas être daté dans le futur.',
            'lignes.*.raison_id.exists' => 'Choisissez une raison de retour active.',
        ]);
        if ($creation && empty($donnees['fournisseur_id']) && empty($donnees['commande_fournisseur_id'])) {
            abort(response()->json(['message' => 'Choisissez le fournisseur.', 'errors' => ['fournisseur_id' => ['Choisissez le fournisseur.']]], 422));
        }
        $donnees['lignes'] = array_values(array_filter($donnees['lignes'], fn (array $l) => (int) $l['quantite'] > 0));
        if ($donnees['lignes'] === []) {
            abort(response()->json(['message' => 'Indiquez au moins une quantité renvoyée.', 'errors' => ['lignes' => ['Indiquez au moins une quantité renvoyée.']]], 422));
        }

        return $donnees;
    }

    /** Erreurs de règle (état du retour ou de son origine) : 422 avec leur message. */
    private function executer(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
