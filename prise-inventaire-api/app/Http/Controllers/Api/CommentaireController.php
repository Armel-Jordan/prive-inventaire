<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commentaire;
use App\Models\CommentaireLecture;
use App\Support\Droits;
use App\Support\PiecesJointes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Commentaires des fiches et des documents (plan de parité fripv2, socle S4.6) : signés et datés, lus et écrits avec le
 * droit de voir la fiche (les fiches sont celles des pièces jointes, leur module doit être actif) ; chacun ne retire que
 * les siens. Ouvrir les commentaires d'une fiche les marque lus pour le compte ; les listes demandent leurs non-lus.
 */
class CommentaireController extends Controller
{
    /** Au plus ce nombre de fiches par demande de non-lus (une page de liste). */
    private const IDS_MAX = 200;

    public function index(Request $request, Droits $droits): JsonResponse
    {
        ['entite' => $entite, 'entite_id' => $id] = $this->fiche($request, $droits);
        $compte = $request->user();
        $lecture = $this->lecture($compte->id, $entite, $id);
        $commentaires = Commentaire::with('auteur:id,nom,email')->where('entite', $entite)->where('entite_id', $id)->orderBy('id')->get();

        $liste = $commentaires->map(fn (Commentaire $c) => [
            'id' => $c->id,
            'texte' => $c->texte,
            'auteur' => $c->auteur?->nom ?: $c->auteur?->email,
            'moi' => $c->auteur_id === $compte->id,
            'non_lu' => $c->id > $lecture->dernier_lu_id && $c->auteur_id !== $compte->id,
            'date' => $c->created_at?->toIso8601String(),
        ])->values();
        // Ouvrir les commentaires les marque lus.
        if ($commentaires->isNotEmpty() && $commentaires->max('id') > $lecture->dernier_lu_id) {
            $lecture->forceFill(['dernier_lu_id' => $commentaires->max('id')])->save();
        }

        return response()->json($liste);
    }

    public function store(Request $request, Droits $droits): JsonResponse
    {
        ['entite' => $entite, 'entite_id' => $id] = $this->fiche($request, $droits);
        $texte = $request->validate(['texte' => ['required', 'string', 'max:2000']])['texte'];
        $compte = $request->user();

        $commentaire = Commentaire::create(['entite' => $entite, 'entite_id' => $id, 'auteur_id' => $compte->id, 'texte' => trim($texte)]);
        $this->lecture($compte->id, $entite, $id)->forceFill(['dernier_lu_id' => $commentaire->id])->save();

        return response()->json([
            'id' => $commentaire->id,
            'texte' => $commentaire->texte,
            'auteur' => $compte->nom ?: $compte->email,
            'moi' => true,
            'non_lu' => false,
            'date' => $commentaire->created_at?->toIso8601String(),
        ], 201);
    }

    /** Retirer un commentaire : seulement le sien, avec le droit de voir sa fiche. */
    public function destroy(Request $request, Droits $droits, int $id): JsonResponse
    {
        $commentaire = Commentaire::findOrFail($id);
        $droits->exiger($request->user(), PiecesJointes::moduleDroits($commentaire->entite), 'voir');
        abort_unless($commentaire->auteur_id === $request->user()->id, 403, __('Seul son auteur peut retirer ce commentaire.'));
        $commentaire->delete();

        return response()->json(['message' => __('Commentaire retiré.')]);
    }

    /**
     * Nombre de commentaires et de non-lus de plusieurs fiches d'un même type (les lignes d'une liste) :
     * `?entite=devis&ids=1,2,3` → { "1": { "total": 2, "non_lus": 1 } }, les fiches sans commentaire omises.
     */
    public function compteurs(Request $request, Droits $droits): JsonResponse
    {
        $donnees = $request->validate([
            'entite' => ['required', Rule::in(PiecesJointes::entites())],
            'ids' => ['required', 'string', 'max:4000'],
        ]);
        $entite = $donnees['entite'];
        $this->verifierModule($request, $entite);
        $droits->exiger($request->user(), PiecesJointes::moduleDroits($entite), 'voir');
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', $donnees['ids']))))), 0, self::IDS_MAX);
        $compte = $request->user();

        $lus = CommentaireLecture::where('user_id', $compte->id)->where('entite', $entite)->whereIn('entite_id', $ids)->pluck('dernier_lu_id', 'entite_id');
        $compteurs = [];
        Commentaire::where('entite', $entite)->whereIn('entite_id', $ids)->get(['id', 'entite_id', 'auteur_id'])
            ->groupBy('entite_id')
            ->each(function ($commentaires, $ficheId) use (&$compteurs, $lus, $compte) {
                $compteurs[$ficheId] = [
                    'total' => $commentaires->count(),
                    'non_lus' => $commentaires->filter(fn ($c) => $c->id > ($lus[$ficheId] ?? 0) && $c->auteur_id !== $compte->id)->count(),
                ];
            });

        return response()->json((object) $compteurs);
    }

    /**
     * Fiche ou document visé : un type connu, de l'entreprise courante (404 sinon), dont le module est actif et que le
     * compte voit (403 sinon).
     *
     * @return array{entite: string, entite_id: int}
     */
    private function fiche(Request $request, Droits $droits): array
    {
        $donnees = $request->validate([
            'entite' => ['required', Rule::in(PiecesJointes::entites())],
            'entite_id' => ['required', 'integer', 'min:1'],
        ]);
        $this->verifierModule($request, $donnees['entite']);
        $droits->exiger($request->user(), PiecesJointes::moduleDroits($donnees['entite']), 'voir');
        PiecesJointes::modele($donnees['entite'])::query()->findOrFail($donnees['entite_id']);

        return ['entite' => $donnees['entite'], 'entite_id' => (int) $donnees['entite_id']];
    }

    private function verifierModule(Request $request, string $entite): void
    {
        $module = PiecesJointes::module($entite);
        $tenant = $request->attributes->get('tenant');
        abort_if($module && $tenant && ! $tenant->hasModule($module), 403, __("Le module « :module » n'est pas activé pour cette entreprise.", ['module' => $module]));
    }

    private function lecture(int $compte, string $entite, int $id): CommentaireLecture
    {
        return CommentaireLecture::firstOrNew(['user_id' => $compte, 'entite' => $entite, 'entite_id' => $id], ['dernier_lu_id' => 0]);
    }
}
