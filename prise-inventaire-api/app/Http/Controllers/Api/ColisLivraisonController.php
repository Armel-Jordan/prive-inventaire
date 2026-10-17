<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BonLivraison;
use App\Models\ColisLivraison;
use App\Services\ColisLivraisons;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Colis d'un bon de livraison : liste et quantités restantes, préparation, modification, suppression. */
class ColisLivraisonController extends Controller
{
    public function __construct(private readonly ColisLivraisons $colis) {}

    public function index(int $id): JsonResponse
    {
        return response()->json($this->colis->presenter(BonLivraison::findOrFail($id)));
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $bon = BonLivraison::findOrFail($id);
        $this->colis->creer($bon, $this->valider($request), $request->user()?->id);

        return response()->json($this->colis->presenter($bon->fresh()), 201);
    }

    public function update(Request $request, int $id, int $colisId): JsonResponse
    {
        $colis = ColisLivraison::where('bon_livraison_id', $id)->findOrFail($colisId);
        $this->colis->modifier($colis, $this->valider($request));

        return response()->json($this->colis->presenter(BonLivraison::findOrFail($id)));
    }

    public function destroy(int $id, int $colisId): JsonResponse
    {
        $this->colis->supprimer(ColisLivraison::where('bon_livraison_id', $id)->findOrFail($colisId));

        return response()->json($this->colis->presenter(BonLivraison::findOrFail($id)));
    }

    private function valider(Request $request): array
    {
        return $request->validate([
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.bon_livraison_ligne_id' => ['required', 'integer'],
            'lignes.*.quantite' => ['required', 'integer', 'min:0', 'max:1000000'],
            'poids_kg' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
