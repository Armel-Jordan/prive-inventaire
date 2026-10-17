<?php

namespace App\Http\Controllers\Prix;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\CalculPrix;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Prix de vente de produits pour un client à une date (simulateur, et plus tard saisie des documents). */
class CalculController extends Controller
{
    public function __invoke(Request $request, CalculPrix $calcul): JsonResponse
    {
        $donnees = $request->validate([
            'client_id' => ['nullable', 'integer', TenantRule::exists('clients')],
            'date' => ['nullable', 'date'],
            'lignes' => ['required', 'array', 'min:1', 'max:200'],
            'lignes.*.produit_id' => ['required', 'integer', TenantRule::exists('produits')],
            'lignes.*.quantite' => ['nullable', 'integer', 'min:1'],
            // Unité de vente du produit (la caisse de 12) ; vide : son unité de stock.
            'lignes.*.unite' => ['nullable', 'string', 'max:20'],
        ]);

        $client = isset($donnees['client_id']) ? Client::findOrFail($donnees['client_id']) : null;
        $lignes = array_map(fn ($l) => ['produit_id' => (int) $l['produit_id'], 'quantite' => (int) ($l['quantite'] ?? 1), 'unite' => $l['unite'] ?? null], $donnees['lignes']);

        return response()->json($calcul->calculer($client, $lignes, isset($donnees['date']) ? Carbon::parse($donnees['date']) : now()));
    }
}
