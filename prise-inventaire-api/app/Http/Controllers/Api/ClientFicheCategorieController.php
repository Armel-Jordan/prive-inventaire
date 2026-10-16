<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientCategorie;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Catégories de clients de l'entreprise ; leur gestion est réservée aux administrateurs (routes). */
class ClientFicheCategorieController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(ClientCategorie::withCount('clients')->orderBy('nom')->get());
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(ClientCategorie::create($this->valider($request)), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $categorie = ClientCategorie::findOrFail($id);
        $categorie->update($this->valider($request, $categorie->id));

        return response()->json($categorie);
    }

    /** Les clients de la catégorie restent, simplement sans catégorie. */
    public function destroy(int $id): JsonResponse
    {
        $categorie = ClientCategorie::findOrFail($id);
        DB::transaction(function () use ($categorie) {
            Client::where('categorie_id', $categorie->id)->update(['categorie_id' => null]);
            $categorie->delete();
        });

        return response()->json(['message' => 'Catégorie supprimée.']);
    }

    private function valider(Request $request, ?int $ignorer = null): array
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:100', TenantRule::unique('client_categories', 'nom', $ignorer)],
            'description' => ['nullable', 'string', 'max:255'],
            'actif' => ['sometimes', 'boolean'],
        ], ['nom.unique' => 'Une catégorie porte déjà ce nom.']);
        $donnees['nom'] = trim($donnees['nom']);

        return $donnees;
    }
}
