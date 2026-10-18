<?php

namespace App\Http\Controllers\Banque;

use App\Http\Controllers\Controller;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\ReleveBancaire;
use App\Models\ReleveBancaireLigne;
use App\Services\Banque\Rapprochements;
use App\Services\Banque\RelevesBancaires;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Relevés bancaires importés : import d'un fichier CSV, OFX ou CAMT (avec aperçu), fichiers importés (suppression
 * d'un import sans opération reliée), opérations et leurs liens aux mouvements,
 * candidats, relier, délier, exclure, rétablir, classer (créer la transaction), appliquer les meilleures correspondances,
 * pointer ce qui est relié dans un rapprochement.
 */
class ReleveBancaireController extends Controller
{
    public function __construct(private readonly RelevesBancaires $releves) {}

    /** Opérations importées d'un compte (période, statut), avec la meilleure correspondance proposée. */
    public function index(Request $request, int $id): JsonResponse
    {
        $compte = CompteBancaire::query()->findOrFail($id);
        $filtres = $request->validate([
            'du' => 'nullable|date',
            'au' => 'nullable|date',
            'statut' => 'nullable|in:non_reliee,partielle,reliee,exclue',
        ]);

        return response()->json(['import_reglages' => $compte->import_reglages] + $this->releves->lignes($compte, $filtres));
    }

    /** Fichiers importés du compte, les plus récents d'abord. */
    public function imports(int $id): JsonResponse
    {
        return response()->json($this->releves->imports(CompteBancaire::query()->findOrFail($id)));
    }

    /** Supprime un fichier importé et ses opérations, si aucune n'est reliée (colonnes mal réglées, mauvais compte). */
    public function destroy(int $releve): JsonResponse
    {
        $import = ReleveBancaire::query()->findOrFail($releve);

        return $this->executer(function () use ($import) {
            $this->releves->supprimerImport($import);

            return response()->json(['message' => __('Relevé « :fichier » supprimé avec ses opérations.', ['fichier' => $import->fichier])]);
        });
    }

    /** Importe un fichier de relevé (CSV, OFX ou CAMT) ; `simulation` : l'aperçu seul. */
    public function store(Request $request, int $id): JsonResponse
    {
        $compte = CompteBancaire::query()->findOrFail($id);
        $donnees = $request->validate([
            'fichier' => 'required|file|max:5120',
            'simulation' => 'sometimes|boolean',
            'colonne_date' => 'nullable|integer|min:1|max:100',
            'colonne_libelle' => 'nullable|integer|min:1|max:100',
            'colonne_montant' => 'nullable|integer|min:1|max:100',
            'colonne_debit' => 'nullable|integer|min:1|max:100',
            'colonne_credit' => 'nullable|integer|min:1|max:100',
            'colonne_reference' => 'nullable|integer|min:1|max:100',
            'ligne_debut' => 'nullable|integer|min:1|max:1000',
            'format_date' => 'nullable|in:jma,amj,mja',
        ]);
        $fichier = $request->file('fichier');
        $reglages = array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, array_filter(
            array_intersect_key($donnees, array_flip(['colonne_date', 'colonne_libelle', 'colonne_montant', 'colonne_debit', 'colonne_credit', 'colonne_reference', 'ligne_debut', 'format_date'])),
            fn ($v) => $v !== null && $v !== '',
        ));

        return $this->executer(fn () => response()->json($this->releves->importer(
            $compte, $fichier->getClientOriginalName(), (string) file_get_contents($fichier->getRealPath()), $reglages, $request->boolean('simulation'), $request->user()?->id,
        )));
    }

    /** Mouvements libres qui peuvent correspondre à une opération (ou une recherche sur toutes les dates). */
    public function candidats(Request $request, int $ligne): JsonResponse
    {
        $operation = ReleveBancaireLigne::query()->findOrFail($ligne);

        return $this->executer(fn () => response()->json($this->releves->candidats($operation, $request->validate(['recherche' => 'nullable|string|max:100'])['recherche'] ?? null)));
    }

    public function relier(Request $request, int $ligne): JsonResponse
    {
        $operation = ReleveBancaireLigne::query()->findOrFail($ligne);
        $donnees = $request->validate(['lignes' => 'required|array|min:1|max:200', 'lignes.*' => 'integer']);

        return $this->executer(fn () => response()->json($this->releves->relier($operation, array_map('intval', $donnees['lignes']))));
    }

    public function delier(Request $request, int $ligne): JsonResponse
    {
        $operation = ReleveBancaireLigne::query()->findOrFail($ligne);
        $donnees = $request->validate(['ligne' => 'nullable|integer']);

        return response()->json($this->releves->delier($operation, $donnees['ligne'] ?? null));
    }

    public function exclure(Request $request, int $ligne): JsonResponse
    {
        $operation = ReleveBancaireLigne::query()->findOrFail($ligne);
        $donnees = $request->validate(['motif' => 'nullable|string|max:255']);

        return $this->executer(fn () => response()->json($this->releves->exclure($operation, $donnees['motif'] ?? null)));
    }

    public function retablir(int $ligne): JsonResponse
    {
        return response()->json($this->releves->retablir(ReleveBancaireLigne::query()->findOrFail($ligne)));
    }

    /** Crée la transaction bancaire d'une opération sans mouvement, la passe au journal et la relie. */
    public function classer(Request $request, int $ligne): JsonResponse
    {
        $operation = ReleveBancaireLigne::query()->findOrFail($ligne);
        $donnees = $request->validate([
            'compte_comptable_id' => 'required|integer',
            'libelle' => 'nullable|string|max:255',
        ]);

        return $this->executer(fn () => response()->json($this->releves->classer(
            $operation, (int) $donnees['compte_comptable_id'], $donnees['libelle'] ?? null, $request->attributes->get('tenant')->id, $request->user()?->id,
        ), 201));
    }

    /** Applique toutes les meilleures correspondances de la période. */
    public function relierSuggestions(Request $request, int $id): JsonResponse
    {
        $compte = CompteBancaire::query()->findOrFail($id);
        $filtres = $request->validate(['du' => 'nullable|date', 'au' => 'nullable|date']);

        return $this->executer(function () use ($compte, $filtres) {
            $nombre = $this->releves->relierSuggestions($compte, $filtres);

            return response()->json(['message' => __(':nombre opération(s) reliée(s).', ['nombre' => $nombre]), 'reliees' => $nombre]);
        });
    }

    /** Pointe dans un rapprochement en cours les mouvements reliés au relevé importé. */
    public function pointerRapprochement(int $id, Rapprochements $rapprochements): JsonResponse
    {
        $rapprochement = RapprochementBancaire::query()->findOrFail($id);

        return $this->executer(fn () => response()->json($rapprochements->pointerReleve($rapprochement)));
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
