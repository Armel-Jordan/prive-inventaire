<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Remarques datées d'une fiche (client, fournisseur…), épinglées en tête. Seuls leur auteur et les
 * administrateurs les modifient ou les suppriment.
 */
abstract class FicheRemarqueController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function modeleParent(): string;

    abstract protected function cleParent(): string;

    /** @return class-string<Model> */
    abstract protected function modele(): string;

    public function index(int $parent): JsonResponse
    {
        $parent = $this->modeleParent()::findOrFail($parent);

        return response()->json($this->modele()::where($this->cleParent(), $parent->getKey())->with('auteur:id,nom')
            ->orderByDesc('epinglee')->latest()->orderByDesc('id')->get());
    }

    public function store(Request $request, int $parent): JsonResponse
    {
        $parent = $this->modeleParent()::findOrFail($parent);
        $donnees = $request->validate([
            'texte' => ['required', 'string', 'max:5000'],
            'epinglee' => ['sometimes', 'boolean'],
        ]);

        $remarque = $this->modele()::create([...$donnees, $this->cleParent() => $parent->getKey(), 'auteur_id' => $request->user()->id]);

        return response()->json($remarque->load('auteur:id,nom'), 201);
    }

    public function update(Request $request, int $parent, int $id): JsonResponse
    {
        $remarque = $this->remarque($request, $parent, $id);
        $remarque->update($request->validate([
            'texte' => ['sometimes', 'required', 'string', 'max:5000'],
            'epinglee' => ['sometimes', 'boolean'],
        ]));

        return response()->json($remarque->load('auteur:id,nom'));
    }

    public function destroy(Request $request, int $parent, int $id): JsonResponse
    {
        $this->remarque($request, $parent, $id)->delete();

        return response()->json(['message' => __('Remarque supprimée.')]);
    }

    private function remarque(Request $request, int $parent, int $id): Model
    {
        $parent = $this->modeleParent()::findOrFail($parent);
        $remarque = $this->modele()::where($this->cleParent(), $parent->getKey())->findOrFail($id);
        $utilisateur = $request->user();
        abort_unless((int) $remarque->auteur_id === (int) $utilisateur->id || $utilisateur->isAdmin(), 403, __('Seul son auteur ou un administrateur peut modifier cette remarque.'));

        return $remarque;
    }
}
