<?php

namespace App\Http\Controllers\Inventaire;

use App\Http\Controllers\Controller;
use App\Services\StocksEntrepots;
use App\Support\FichierCsv;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Stock par entrepôt (multi-entrepôts) : la somme des scans de chaque produit, par entrepôt de leur secteur ; CSV. */
class StockEntrepotsController extends Controller
{
    public function __invoke(Request $request, StocksEntrepots $stocks): JsonResponse|Response
    {
        $filtres = $request->validate([
            'recherche' => ['nullable', 'string', 'max:100'],
            'entrepot_id' => ['nullable', 'integer', TenantRule::exists('entrepots')],
        ]);
        $resultat = $stocks->stock($filtres['recherche'] ?? null, $filtres['entrepot_id'] ?? null);
        if ($request->query('format') !== 'csv') {
            return response()->json($resultat);
        }

        $colonnes = collect($resultat['entrepots']);
        $lignes = array_map(fn (array $p) => [$p['numero'], $p['description'], $p['mesure'],
            ...$colonnes->map(fn (array $e) => (float) ($p['entrepots'][$e['id']] ?? 0))->all(), (float) $p['sans_entrepot'], (float) $p['total']], $resultat['produits']);

        return FichierCsv::reponse('stock-par-entrepot.csv',
            ['produit', 'description', 'unite', ...$colonnes->map(fn (array $e) => $e['code'])->all(), 'sans_entrepot', 'total'], $lignes);
    }
}
