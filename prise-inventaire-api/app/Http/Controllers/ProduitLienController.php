<?php

namespace App\Http\Controllers;

use App\Models\ProduitLien;
use App\Models\ProduitTenant;
use App\Services\LiensProduits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Liens d'un produit : composants (ensemble), annexes, équivalents ; et les ensembles qui le contiennent. */
class ProduitLienController extends Controller
{
    public function __construct(private readonly LiensProduits $liens) {}

    public function index(int $produit): JsonResponse
    {
        return response()->json($this->liens->liste(ProduitTenant::query()->findOrFail($produit)));
    }

    public function store(Request $request, int $produit): JsonResponse
    {
        $produit = ProduitTenant::query()->findOrFail($produit);
        $donnees = $request->validate([
            'type' => ['required', Rule::in(ProduitLien::TYPES)],
            'lie_id' => ['required', 'integer'],
            'quantite' => ['nullable', 'numeric', 'gt:0', 'max:999999'],
            'remarque' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->liens->ajouter($produit, $donnees), 201);
    }

    public function update(Request $request, int $produit, int $id): JsonResponse
    {
        $produit = ProduitTenant::query()->findOrFail($produit);
        $donnees = $request->validate([
            'quantite' => ['sometimes', 'numeric', 'gt:0', 'max:999999'],
            'remarque' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->liens->modifier(ProduitLien::query()->where('produit_id', $produit->id)->findOrFail($id), $donnees));
    }

    public function destroy(int $produit, int $id): JsonResponse
    {
        $produit = ProduitTenant::query()->findOrFail($produit);
        $this->liens->retirer(ProduitLien::query()->where('produit_id', $produit->id)->findOrFail($id));

        return response()->json(['message' => __('Lien retiré.')]);
    }
}
