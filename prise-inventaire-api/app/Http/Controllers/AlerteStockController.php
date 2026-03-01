<?php

namespace App\Http\Controllers;

use App\Models\ProduitTenant;
use App\Models\ScanTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AlerteStockController extends Controller
{
    /**
     * Récupère les produits en alerte de stock
     */
    public function index(): JsonResponse
    {
        // Récupérer les produits avec seuil d'alerte défini
        $produits = ProduitTenant::whereNotNull('seuil_alerte')
            ->where('seuil_alerte', '>', 0)
            ->where('actif', true)
            ->get();

        // Calculer le stock actuel pour chaque produit (somme des scans)
        $alertes = [];

        foreach ($produits as $produit) {
            $stockActuel = ScanTenant::where('numero', $produit->numero)
                ->whereNull('deleted_at')
                ->sum('quantite');

            if ($stockActuel < $produit->seuil_alerte) {
                $alertes[] = [
                    'id' => $produit->id,
                    'numero' => $produit->numero,
                    'description' => $produit->description,
                    'type' => $produit->type,
                    'unite_mesure' => $produit->mesure,
                    'stock_actuel' => (float) $stockActuel,
                    'seuil_alerte' => (float) $produit->seuil_alerte,
                    'deficit' => (float) ($produit->seuil_alerte - $stockActuel),
                    'pourcentage' => $produit->seuil_alerte > 0
                        ? round(($stockActuel / $produit->seuil_alerte) * 100, 1)
                        : 0,
                    'criticite' => $this->calculerCriticite($stockActuel, $produit->seuil_alerte),
                ];
            }
        }

        // Trier par criticité (critique en premier)
        usort($alertes, function ($a, $b) {
            $order = ['critique' => 0, 'warning' => 1, 'info' => 2];
            return ($order[$a['criticite']] ?? 3) <=> ($order[$b['criticite']] ?? 3);
        });

        return response()->json([
            'alertes' => $alertes,
            'total' => count($alertes),
            'critiques' => count(array_filter($alertes, fn($a) => $a['criticite'] === 'critique')),
            'warnings' => count(array_filter($alertes, fn($a) => $a['criticite'] === 'warning')),
        ]);
    }

    /**
     * Met à jour le seuil d'alerte d'un produit
     */
    public function updateSeuil(Request $request, int $produitId): JsonResponse
    {
        $request->validate([
            'seuil_alerte' => 'required|numeric|min:0',
        ]);

        $produit = ProduitTenant::findOrFail($produitId);
        $produit->seuil_alerte = $request->seuil_alerte;
        $produit->save();

        return response()->json([
            'success' => true,
            'message' => 'Seuil d\'alerte mis à jour',
            'produit' => $produit,
        ]);
    }

    /**
     * Met à jour les seuils d'alerte en lot
     */
    public function updateSeuilsBatch(Request $request): JsonResponse
    {
        $request->validate([
            'produits' => 'required|array',
            'produits.*.id' => 'required|integer',
            'produits.*.seuil_alerte' => 'required|numeric|min:0',
        ]);

        $updated = 0;

        DB::transaction(function () use ($request, &$updated) {
            foreach ($request->produits as $item) {
                $produit = ProduitTenant::find($item['id']);
                if ($produit) {
                    $produit->seuil_alerte = $item['seuil_alerte'];
                    $produit->save();
                    $updated++;
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => "$updated seuil(s) mis à jour",
            'updated' => $updated,
        ]);
    }

    /**
     * Statistiques des alertes
     */
    public function stats(): JsonResponse
    {
        $produits = ProduitTenant::whereNotNull('seuil_alerte')
            ->where('seuil_alerte', '>', 0)
            ->where('actif', true)
            ->get();

        $stats = [
            'total_produits_surveilles' => $produits->count(),
            'en_alerte' => 0,
            'critiques' => 0,
            'warnings' => 0,
            'ok' => 0,
        ];

        foreach ($produits as $produit) {
            $stockActuel = ScanTenant::where('numero', $produit->numero)
                ->whereNull('deleted_at')
                ->sum('quantite');

            if ($stockActuel < $produit->seuil_alerte) {
                $stats['en_alerte']++;
                $criticite = $this->calculerCriticite($stockActuel, $produit->seuil_alerte);
                if ($criticite === 'critique') {
                    $stats['critiques']++;
                } else {
                    $stats['warnings']++;
                }
            } else {
                $stats['ok']++;
            }
        }

        return response()->json($stats);
    }

    /**
     * Calcule le niveau de criticité
     */
    private function calculerCriticite(float $stockActuel, float $seuil): string
    {
        if ($seuil <= 0) return 'info';

        $ratio = $stockActuel / $seuil;

        if ($ratio <= 0.25) return 'critique';  // Stock < 25% du seuil
        if ($ratio <= 0.5) return 'warning';    // Stock < 50% du seuil
        return 'info';
    }
}
