<?php

namespace App\Http\Controllers;

use App\Models\ProduitCodeBarre;
use App\Models\ProduitTenant;
use App\Services\CodesBarresProduits;
use App\Support\CodesBarres;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Codes-barres d'un produit : liste, ajout, modification, retrait. */
class ProduitCodeBarreController extends Controller
{
    public function __construct(private readonly CodesBarresProduits $codes) {}

    public function index(int $produit): JsonResponse
    {
        $produit = ProduitTenant::query()->findOrFail($produit);

        return response()->json(ProduitCodeBarre::query()->where('produit_id', $produit->id)->orderByDesc('principal')->orderBy('id')->get());
    }

    public function store(Request $request, int $produit): JsonResponse
    {
        $produit = ProduitTenant::query()->findOrFail($produit);

        return response()->json($this->codes->enregistrer($produit, $this->valider($request)), 201);
    }

    public function update(Request $request, int $produit, int $id): JsonResponse
    {
        $produit = ProduitTenant::query()->findOrFail($produit);
        $codeBarre = ProduitCodeBarre::query()->where('produit_id', $produit->id)->findOrFail($id);

        return response()->json($this->codes->enregistrer($produit, $this->valider($request), $codeBarre));
    }

    public function destroy(int $produit, int $id): JsonResponse
    {
        $produit = ProduitTenant::query()->findOrFail($produit);
        $this->codes->supprimer(ProduitCodeBarre::query()->where('produit_id', $produit->id)->findOrFail($id));

        return response()->json(['message' => 'Code-barres retiré.']);
    }

    private function valider(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'type' => ['nullable', Rule::in(CodesBarres::TYPES)],
            'quantite' => ['nullable', 'numeric', 'min:0.001', 'max:999999'],
            'principal' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
