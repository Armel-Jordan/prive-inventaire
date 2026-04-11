<?php

namespace App\Http\Controllers;

use App\Models\MouvementTenant;
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
    private function tenantId(): int
    {
        $tenant = request()->attributes->get('tenant');
        if ($tenant) {
            return $tenant->id;
        }
        return auth()->user()?->tenant_id ?? 0;
    }

    public function index(): JsonResponse
    {
        $tenantId = $this->tenantId();
        // Récupérer les produits avec seuil d'alerte défini
        $produits = ProduitTenant::where('tenant_id', $tenantId)
            ->whereNotNull('seuil_alerte')
            ->where('seuil_alerte', '>', 0)
            ->where('actif', true)
            ->get();

        // Calculer le stock actuel pour chaque produit (somme des scans)
        $alertes = [];

        foreach ($produits as $produit) {
            $stockActuel = ScanTenant::where('tenant_id', $tenantId)
                ->where('numero', $produit->numero)
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
            'critiques' => count(array_filter($alertes, fn ($a) => $a['criticite'] === 'critique')),
            'warnings' => count(array_filter($alertes, fn ($a) => $a['criticite'] === 'warning')),
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

        $tenantId = $this->tenantId();
        $produit = ProduitTenant::where('tenant_id', $tenantId)->findOrFail($produitId);
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
        $tenantId = $this->tenantId();
        $produits = ProduitTenant::where('tenant_id', $tenantId)
            ->whereNotNull('seuil_alerte')
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
            $stockActuel = ScanTenant::where('tenant_id', $tenantId)
                ->where('numero', $produit->numero)
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
     * Prévisions de stock : tous les produits actifs avec stock actuel et consommation
     */
    public function previsions(): JsonResponse
    {
        $tenantId = $this->tenantId();
        $produits = ProduitTenant::where('tenant_id', $tenantId)
            ->where('actif', true)
            ->get();

        $depuis = now()->subDays(30);
        $result = [];

        foreach ($produits as $produit) {
            $stockActuel = (float) ScanTenant::where('tenant_id', $tenantId)
                ->where('numero', $produit->numero)
                ->whereNull('deleted_at')
                ->sum('quantite');

            // Consommation : mouvements de type sortie sur 30 jours
            $consommation30j = (float) MouvementTenant::where('tenant_id', $tenantId)
                ->where('numero', $produit->numero)
                ->where('action', 'sortie')
                ->where('created_at', '>=', $depuis)
                ->whereNull('deleted_at')
                ->sum('quantite');

            $consommationJour = round($consommation30j / 30, 2);
            $joursRestants = $consommationJour > 0
                ? (int) floor($stockActuel / $consommationJour)
                : null;

            $seuilMin = $produit->seuil_alerte ? (float) $produit->seuil_alerte : null;

            $statut = 'ok';
            if ($seuilMin !== null) {
                if ($stockActuel <= $seuilMin * 0.5) {
                    $statut = 'critique';
                } elseif ($stockActuel <= $seuilMin) {
                    $statut = 'bas';
                }
            } elseif ($joursRestants !== null && $joursRestants <= 7) {
                $statut = 'critique';
            } elseif ($joursRestants !== null && $joursRestants <= 14) {
                $statut = 'bas';
            }

            $result[] = [
                'id'                   => $produit->id,
                'numero'               => $produit->numero,
                'description'          => $produit->description,
                'unite_mesure'         => $produit->mesure,
                'stock_actuel'         => $stockActuel,
                'stock_min'            => $seuilMin,
                'consommation_jour'    => $consommationJour,
                'jours_restants'       => $joursRestants,
                'statut'               => $statut,
            ];
        }

        // Trier : critique → bas → ok, puis par jours restants
        usort($result, function ($a, $b) {
            $order = ['critique' => 0, 'bas' => 1, 'ok' => 2];
            $diff = ($order[$a['statut']] ?? 3) <=> ($order[$b['statut']] ?? 3);
            if ($diff !== 0) return $diff;
            return ($a['jours_restants'] ?? 9999) <=> ($b['jours_restants'] ?? 9999);
        });

        $critiques = count(array_filter($result, fn ($p) => $p['statut'] === 'critique'));
        $bas       = count(array_filter($result, fn ($p) => $p['statut'] === 'bas'));

        return response()->json([
            'produits'  => $result,
            'total'     => count($result),
            'critiques' => $critiques,
            'bas'       => $bas,
            'ok'        => count($result) - $critiques - $bas,
        ]);
    }

    /**
     * Calcule le niveau de criticité
     */
    private function calculerCriticite(float $stockActuel, float $seuil): string
    {
        if ($seuil <= 0) {
            return 'info';
        }

        $ratio = $stockActuel / $seuil;

        if ($ratio <= 0.25) {
            return 'critique';
        }  // Stock < 25% du seuil
        if ($ratio <= 0.5) {
            return 'warning';
        }    // Stock < 50% du seuil

        return 'info';
    }
}
