<?php

namespace App\Http\Controllers\Comptabilite;

use App\Http\Controllers\Controller;
use App\Models\ExerciceComptable;
use App\Services\Comptabilite\Budgets;
use App\Support\Droits;
use App\Support\FichierCsv;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Budgets : feuille d'un exercice (comptes budgétables × périodes), saisie par cellules, répartition d'un total, copie
 * d'un autre exercice, comptes du budget, et budget et réel (avec le droit de voir les états comptables).
 */
class BudgetController extends Controller
{
    public function __construct(private readonly Budgets $budgets) {}

    /** Feuille du budget d'un exercice (par défaut celui du jour, sinon le plus récent), et la liste des exercices. */
    public function index(Request $request): JsonResponse
    {
        $donnees = $request->validate(['exercice_id' => ['nullable', 'integer']]);
        $exercice = isset($donnees['exercice_id']) ? ExerciceComptable::query()->findOrFail($donnees['exercice_id']) : $this->budgets->exerciceParDefaut();

        return response()->json(['exercices' => $this->exercices()] + ($exercice ? $this->budgets->feuille($exercice) : ['exercice' => null]));
    }

    /** Enregistre des cellules (compte × période) ; un montant vide ou nul retire la cellule. Renvoie la feuille. */
    public function update(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'exercice_id' => ['required', 'integer'],
            'cellules' => ['required', 'array', 'min:1', 'max:5000'],
            'cellules.*.compte_id' => ['required', 'integer'],
            'cellules.*.periode_id' => ['required', 'integer'],
            'cellules.*.montant' => ['nullable', 'numeric', 'between:-9999999999999,9999999999999'],
        ]);
        $exercice = ExerciceComptable::query()->findOrFail($donnees['exercice_id']);

        return $this->executer(function () use ($exercice, $donnees, $request) {
            $this->budgets->enregistrer($exercice, $donnees['cellules'], $request->user()?->id);

            return response()->json($this->budgets->feuille($exercice));
        });
    }

    /** Répartit un total d'un compte également entre les périodes. */
    public function repartir(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'exercice_id' => ['required', 'integer'],
            'compte_id' => ['required', 'integer'],
            'total' => ['required', 'numeric', 'between:-9999999999999,9999999999999'],
        ]);
        $exercice = ExerciceComptable::query()->findOrFail($donnees['exercice_id']);

        return $this->executer(function () use ($exercice, $donnees, $request) {
            $this->budgets->repartir($exercice, (int) $donnees['compte_id'], (float) $donnees['total'], $request->user()?->id);

            return response()->json($this->budgets->feuille($exercice));
        });
    }

    /** Copie le budget d'un autre exercice, ajusté d'un pourcentage ; remplacer ou ne remplir que les cellules vides. */
    public function copier(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'exercice_id' => ['required', 'integer'],
            'source_exercice_id' => ['required', 'integer'],
            'pourcentage' => ['nullable', 'numeric', 'between:-100,1000'],
            'remplacer' => ['sometimes', 'boolean'],
        ]);
        $cible = ExerciceComptable::query()->findOrFail($donnees['exercice_id']);
        $source = ExerciceComptable::query()->findOrFail($donnees['source_exercice_id']);

        return $this->executer(function () use ($source, $cible, $donnees, $request) {
            $resultat = $this->budgets->copier($source, $cible, (float) ($donnees['pourcentage'] ?? 0), $request->boolean('remplacer'), $request->user()?->id);
            $message = "Budget de l'exercice {$source->libelle} copié : {$resultat['cellules']} cellule(s), {$resultat['comptes']} compte(s).";
            if ($resultat['ignorees']) {
                $message .= " {$resultat['ignorees']} montant(s) hors des dates de l'exercice ignoré(s).";
            }

            return response()->json(['message' => $message, 'resultat' => $resultat] + $this->budgets->feuille($cible));
        });
    }

    /** Ajoute des comptes de détail au budget, ou les en retire. */
    public function comptes(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'comptes' => ['required', 'array', 'min:1', 'max:5000'],
            'comptes.*' => ['integer'],
            'budgetable' => ['required', 'boolean'],
        ]);
        $nombre = $this->budgets->marquerComptes(array_map('intval', $donnees['comptes']), (bool) $donnees['budgetable']);

        return response()->json([
            'message' => $donnees['budgetable'] ? "{$nombre} compte(s) ajouté(s) au budget." : "{$nombre} compte(s) retiré(s) du budget.",
            'modifies' => $nombre,
        ]);
    }

    /** Budget et réel d'une suite de périodes ; CSV avec format=csv. Le réel vient du journal : voir les états aussi. */
    public function comparaison(Request $request, Droits $droits): JsonResponse|Response
    {
        if (! $droits->permet($request->user(), 'comptabilite', 'etats')) {
            return response()->json(['message' => $droits->messageRefus('comptabilite', 'etats'), 'module' => 'comptabilite', 'action' => 'etats'], 403);
        }
        $donnees = $request->validate([
            'exercice_id' => ['nullable', 'integer'],
            'periode_du' => ['nullable', 'integer'],
            'periode_au' => ['nullable', 'integer'],
            'format' => ['nullable', 'in:csv'],
        ]);
        $exercice = isset($donnees['exercice_id']) ? ExerciceComptable::query()->findOrFail($donnees['exercice_id']) : $this->budgets->exerciceParDefaut();
        if (! $exercice) {
            return response()->json(['message' => "Créez d'abord un exercice comptable."], 422);
        }

        return $this->executer(function () use ($exercice, $donnees) {
            $comparaison = $this->budgets->comparaison($exercice, $donnees['periode_du'] ?? null, $donnees['periode_au'] ?? null);
            if (($donnees['format'] ?? null) !== 'csv') {
                return response()->json(['exercices' => $this->exercices()] + $comparaison);
            }
            $lignes = array_map(fn (array $l) => [
                $l['numero'], $l['libelle'], $l['nature'], FichierCsv::montant($l['budget']), FichierCsv::montant($l['reel']), FichierCsv::montant($l['ecart']),
                $l['taux'] === null ? '' : str_replace('.', ',', (string) $l['taux']),
            ], $comparaison['lignes']);
            foreach (['produits' => 'Total des produits', 'charges' => 'Total des charges', 'resultat' => 'Résultat'] as $cle => $libelle) {
                $t = $comparaison['totaux'][$cle];
                $lignes[] = ['', $libelle, '', FichierCsv::montant($t['budget']), FichierCsv::montant($t['reel']), FichierCsv::montant($t['ecart']), ''];
            }

            return FichierCsv::reponse("budget-et-reel-{$comparaison['du']}-{$comparaison['au']}.csv",
                ['Compte', 'Libellé', 'Nature', 'Budget', 'Réel', 'Écart', 'Réalisé (%)'], $lignes);
        });
    }

    private function exercices(): array
    {
        return ExerciceComptable::query()->orderByDesc('date_debut')->get(['id', 'libelle', 'date_debut', 'date_fin', 'statut'])->all();
    }

    private function executer(callable $action): JsonResponse|Response
    {
        try {
            return $action();
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
