<?php

namespace App\Http\Controllers\Prix;

use App\Http\Controllers\Controller;
use App\Models\ListePrix;
use App\Models\ProduitTenant;
use App\Models\RevisionPrix;
use App\Models\TachePlanifiee;
use App\Services\Planificateur;
use App\Services\RevisionsPrix;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Révisions de prix planifiées (plan de parité fripv2, phase 1.2d) : de nouveaux prix préparés en lot, planifiés à une
 * date d'entrée en vigueur ou appliqués tout de suite. Consulter demande le droit de voir les tarifs ; le reste, l'action
 * « réviser les prix » (voir App\Support\Droits).
 */
class RevisionController extends Controller
{
    /** Code de la tâche planifiée qui applique les révisions à leur date. */
    public const TACHE = 'revisions_prix';

    public function index(): JsonResponse
    {
        $revisions = RevisionPrix::with(['auteur:id,nom', 'auteurApplication:id,nom', 'auteurAnnulation:id,nom', 'listePrix:id,nom'])
            ->withCount('lignes')
            ->orderByRaw("CASE statut WHEN 'planifiee' THEN 0 WHEN 'brouillon' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->get();
        $tache = TachePlanifiee::query()->where('code', self::TACHE)->first();

        return response()->json([
            'data' => $revisions->map(fn (RevisionPrix $r) => $this->presenter($r))->all(),
            // Sans la tâche planifiée active, une révision planifiée ne s'applique pas à sa date : l'écran le dit.
            'tache' => ['active' => (bool) $tache?->actif, 'prochaine_execution' => $tache?->prochaine_execution?->toIso8601String()],
            'listes' => ListePrix::where('actif', true)->orderBy('nom')->get(['id', 'nom', 'ajustement_pourcentage', 'precision']),
            'categories' => ProduitTenant::whereNotNull('categorie')->where('categorie', '!=', '')->distinct()->orderBy('categorie')->pluck('categorie'),
        ]);
    }

    public function show(RevisionsPrix $revisions, int $id): JsonResponse
    {
        $revision = RevisionPrix::with(['auteur:id,nom', 'auteurApplication:id,nom', 'auteurAnnulation:id,nom', 'listePrix:id,nom'])->withCount('lignes')->findOrFail($id);
        $lignes = $revision->lignes()->orderBy('id')->get();

        return response()->json($this->presenter($revision) + [
            'lignes' => $revisions->presenter($lignes, $revision->statut === RevisionPrix::APPLIQUEE),
        ]);
    }

    /** Nouveaux prix d'un ajustement, à partir des prix en place : rien n'est enregistré. */
    public function apercu(Request $request, RevisionsPrix $revisions): JsonResponse
    {
        $reglage = $request->validate([
            'categorie' => ['nullable', 'string', 'max:100'],
            'cible' => ['required', Rule::in(RevisionPrix::CIBLES)],
            'liste_prix_id' => ['nullable', 'required_if:cible,liste', 'integer', TenantRule::exists('listes_prix')],
            'type' => ['required', Rule::in(RevisionPrix::TYPES_AJUSTEMENT)],
            'valeur' => ['required', 'numeric', 'between:-1000000000,1000000000'],
            'inclure_inactifs' => ['nullable', 'boolean'],
        ]);
        $lignes = $revisions->ajuster($reglage);

        return response()->json(['lignes' => $revisions->presenter($lignes), 'total' => count($lignes)]);
    }

    public function store(Request $request, RevisionsPrix $revisions): JsonResponse
    {
        $revision = $revisions->enregistrer(null, $this->valider($request), $request->user());

        return $this->show($revisions, $revision->id)->setStatusCode(201);
    }

    public function update(Request $request, RevisionsPrix $revisions, int $id): JsonResponse
    {
        $revisions->enregistrer(RevisionPrix::findOrFail($id), $this->valider($request), $request->user());

        return $this->show($revisions, $id);
    }

    /**
     * Fixe la date d'entrée en vigueur. La tâche qui applique les révisions est activée à la première planification si
     * personne ne l'a encore réglée (sinon rien ne s'appliquerait) ; une tâche désactivée exprès le reste.
     */
    public function planifier(Request $request, RevisionsPrix $revisions, Planificateur $planificateur, int $id): JsonResponse
    {
        $donnees = $request->validate([
            'date_effet' => ['required', 'date', 'after_or_equal:today', 'before:2100-01-01'],
        ], [
            'date_effet.after_or_equal' => __("La date d'entrée en vigueur ne peut pas être passée : pour un effet immédiat, appliquez la révision."),
        ]);
        $revisions->planifier(RevisionPrix::findOrFail($id), $donnees['date_effet'], $request->user());

        $tache = $planificateur->taches()->firstWhere('code', self::TACHE);
        if ($tache && ! $tache->actif && $tache->derniere_execution === null && $tache->created_at?->equalTo($tache->updated_at)) {
            $tache->actif = true;
            $tache->prochaine_execution = $planificateur->prochaine($tache, now());
            $tache->save();
        }

        return $this->show($revisions, $id);
    }

    public function appliquer(Request $request, RevisionsPrix $revisions, int $id): JsonResponse
    {
        $nombre = $revisions->appliquer(RevisionPrix::findOrFail($id), $request->user());

        return response()->json(['message' => __('Révision appliquée : :nombre prix mis à jour.', ['nombre' => $nombre])] + $this->show($revisions, $id)->getData(true));
    }

    public function annuler(Request $request, RevisionsPrix $revisions, int $id): JsonResponse
    {
        $revisions->annuler(RevisionPrix::findOrFail($id), $request->user());

        return $this->show($revisions, $id);
    }

    public function destroy(RevisionsPrix $revisions, int $id): JsonResponse
    {
        $revisions->supprimer(RevisionPrix::findOrFail($id));

        return response()->json(['message' => __('Révision supprimée.')]);
    }

    private function valider(Request $request): array
    {
        return $request->validate([
            'nom' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'date_effet' => ['nullable', 'date', 'before:2100-01-01'],
            'categorie' => ['nullable', 'string', 'max:100'],
            'cible' => ['nullable', Rule::in(RevisionPrix::CIBLES)],
            'liste_prix_id' => ['nullable', 'integer', TenantRule::exists('listes_prix')],
            'type_ajustement' => ['nullable', Rule::in(RevisionPrix::TYPES_AJUSTEMENT)],
            'valeur_ajustement' => ['nullable', 'numeric', 'between:-1000000000,1000000000'],
            'lignes' => ['present', 'array', 'max:'.RevisionsPrix::LIGNES_MAX],
            'lignes.*.produit_id' => ['required', 'integer'],
            'lignes.*.liste_prix_id' => ['nullable', 'integer'],
            'lignes.*.nouveau_prix' => ['required', 'numeric', 'min:0', 'max:99999999999'],
        ]);
    }

    private function presenter(RevisionPrix $revision): array
    {
        return [
            'id' => $revision->id,
            'nom' => $revision->nom,
            'notes' => $revision->notes,
            'date_effet' => $revision->date_effet?->toDateString(),
            'statut' => $revision->statut,
            'categorie' => $revision->categorie,
            'cible' => $revision->cible,
            'liste_prix_id' => $revision->liste_prix_id,
            'liste' => $revision->listePrix?->nom,
            'type_ajustement' => $revision->type_ajustement,
            'valeur_ajustement' => $revision->valeur_ajustement,
            'lignes_count' => (int) $revision->lignes_count,
            'cree_par' => $revision->auteur?->nom,
            'created_at' => $revision->created_at?->toIso8601String(),
            'updated_at' => $revision->updated_at?->toIso8601String(),
            'planifiee_le' => $revision->planifiee_le?->toIso8601String(),
            'appliquee_le' => $revision->appliquee_le?->toIso8601String(),
            // Vide pour une révision appliquée : par la tâche planifiée.
            'appliquee_par' => $revision->auteurApplication?->nom,
            'annulee_le' => $revision->annulee_le?->toIso8601String(),
            'annulee_par' => $revision->auteurAnnulation?->nom,
        ];
    }
}
