<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Listes rattachées à une fiche (contacts, adresses, comptes… d'un client ou d'un fournisseur) : même
 * cycle de vie, et au plus un élément « principal » par fiche. Chaque liste fournit sa fiche parente,
 * son modèle, ses règles et sa colonne principale.
 */
abstract class FicheElementController extends Controller
{
    /** @return class-string<Model> Modèle de la fiche parente (Client, Fournisseur…). */
    abstract protected function modeleParent(): string;

    /** Colonne qui rattache l'élément à sa fiche (client_id, fournisseur_id…). */
    abstract protected function cleParent(): string;

    /** @return class-string<Model> */
    abstract protected function modele(): string;

    abstract protected function regles(): array;

    abstract protected function colonnePrincipale(): string;

    abstract protected function ordre(): string;

    /** Relations chargées avec chaque élément. */
    protected function avec(): array
    {
        return [];
    }

    /** Mise en forme de la saisie avant validation (ex. majuscules, espaces retirés). */
    protected function normaliser(Request $request): void {}

    /** Données ajoutées à l'enregistrement après validation (ex. auteur de la modification). */
    protected function completer(array $donnees, Request $request): array
    {
        return $donnees;
    }

    public function index(int $parent): JsonResponse
    {
        $parent = $this->parent($parent);

        return response()->json($this->modele()::with($this->avec())->where($this->cleParent(), $parent->getKey())
            ->orderByDesc($this->colonnePrincipale())->orderBy($this->ordre())->get());
    }

    public function store(Request $request, int $parent): JsonResponse
    {
        $parent = $this->parent($parent);
        $this->normaliser($request);
        $donnees = $this->completer($request->validate($this->regles()), $request);

        $element = DB::transaction(function () use ($parent, $donnees) {
            $element = $this->modele()::create([...$donnees, $this->cleParent() => $parent->getKey()]);
            $this->garderUnSeulPrincipal($element);

            return $element;
        });

        return response()->json($element->refresh()->load($this->avec()), 201);
    }

    public function update(Request $request, int $parent, int $id): JsonResponse
    {
        $element = $this->element($parent, $id);
        $this->normaliser($request);
        $donnees = $this->completer($request->validate($this->regles()), $request);

        DB::transaction(function () use ($element, $donnees) {
            $element->update($donnees);
            $this->garderUnSeulPrincipal($element);
        });

        return response()->json($element->refresh()->load($this->avec()));
    }

    public function destroy(int $parent, int $id): JsonResponse
    {
        $this->element($parent, $id)->delete();

        return response()->json(['message' => 'Supprimé.']);
    }

    /** Fiche parente dans l'entreprise courante (404 sinon). */
    private function parent(int $id): Model
    {
        return $this->modeleParent()::findOrFail($id);
    }

    private function element(int $parent, int $id): Model
    {
        $parent = $this->parent($parent);

        return $this->modele()::where($this->cleParent(), $parent->getKey())->findOrFail($id);
    }

    private function garderUnSeulPrincipal(Model $element): void
    {
        $colonne = $this->colonnePrincipale();
        if ($element->{$colonne}) {
            $this->modele()::where($this->cleParent(), $element->{$this->cleParent()})->whereKeyNot($element->getKey())->update([$colonne => false]);
        }
    }
}
