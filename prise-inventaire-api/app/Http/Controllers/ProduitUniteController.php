<?php

namespace App\Http\Controllers;

use App\Models\ProduitTenant;
use App\Models\ProduitUnite;
use App\Models\Unite;
use App\Services\UnitesProduits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Unités de vente d'un produit : liste, facteur proposé d'après les conversions, ajout, modification, retrait. */
class ProduitUniteController extends Controller
{
    public function __construct(private readonly UnitesProduits $unites) {}

    /** Les unités du produit et les unités du référentiel qu'il peut prendre, avec le facteur proposé de chacune. */
    public function index(int $produit): JsonResponse
    {
        $produit = ProduitTenant::query()->findOrFail($produit);
        $prises = ProduitUnite::query()->where('produit_id', $produit->id)->pluck('unite_id')->all();
        $disponibles = Unite::query()->where('actif', true)->whereNotIn('id', $prises)->orderBy('code')->get(['id', 'code', 'libelle', 'decimales'])
            ->reject(fn (Unite $u) => mb_strtoupper(trim($u->code)) === mb_strtoupper(trim((string) $produit->mesure)))
            ->map(fn (Unite $u) => $u->only(['id', 'code', 'libelle', 'decimales']) + ['facteur_propose' => $this->unites->facteurPropose($produit, $u)])
            ->values();

        return response()->json(['mesure' => $produit->mesure, 'unites' => $this->unites->liste($produit), 'disponibles' => $disponibles]);
    }

    public function store(Request $request, int $produit): JsonResponse
    {
        $produit = ProduitTenant::query()->findOrFail($produit);

        return response()->json($this->unites->enregistrer($produit, $this->valider($request, true)), 201);
    }

    public function update(Request $request, int $produit, int $id): JsonResponse
    {
        $produit = ProduitTenant::query()->findOrFail($produit);
        $unite = ProduitUnite::query()->where('produit_id', $produit->id)->findOrFail($id);

        return response()->json($this->unites->enregistrer($produit, $this->valider($request, false), $unite));
    }

    public function destroy(int $produit, int $id): JsonResponse
    {
        $produit = ProduitTenant::query()->findOrFail($produit);
        $this->unites->supprimer(ProduitUnite::query()->where('produit_id', $produit->id)->findOrFail($id));

        return response()->json(['message' => 'Unité retirée.']);
    }

    private function valider(Request $request, bool $creation): array
    {
        return $request->validate([
            'unite_id' => [$creation ? 'required' : 'sometimes', 'integer'],
            'facteur' => ['nullable', 'numeric', 'gt:0', 'max:1000000000'],
            'prix' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'par_defaut' => ['sometimes', 'boolean'],
        ]);
    }
}
