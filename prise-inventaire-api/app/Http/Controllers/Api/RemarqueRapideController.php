<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RemarqueRapide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Remarques rapides : textes réutilisables, classés par catégorie libre, insérés dans les notes. */
class RemarqueRapideController extends Controller
{
    public function index(): JsonResponse
    {
        // Les remarques non classées viennent après les catégories.
        return response()->json(RemarqueRapide::orderByRaw('categorie IS NULL')->orderBy('categorie')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(RemarqueRapide::create($this->valider($request)), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $remarque = RemarqueRapide::findOrFail($id);
        $remarque->update($this->valider($request));

        return response()->json($remarque);
    }

    public function destroy(int $id): JsonResponse
    {
        RemarqueRapide::findOrFail($id)->delete();

        return response()->json(['message' => 'Remarque rapide supprimée.']);
    }

    private function valider(Request $request): array
    {
        $donnees = $request->validate([
            'categorie' => ['nullable', 'string', 'max:60'],
            'texte' => ['required', 'string', 'max:2000'],
        ]);
        $donnees['categorie'] = filled($donnees['categorie'] ?? null) ? trim($donnees['categorie']) : null;

        return $donnees;
    }
}
