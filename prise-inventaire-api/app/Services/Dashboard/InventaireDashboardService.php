<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class InventaireDashboardService
{
    /**
     * Agrège produits (stock et alertes), secteurs, employés actifs et mouvements de la période.
     *
     * Tables :
     *   - produits                  (tenant_id, actif, seuil_alerte, deleted_at)
     *   - inventaire_scan           (stock d'un produit = somme de ses quantités scannées, comme l'écran Alertes)
     *   - secteurs, employes        (actif, deleted_at)
     *   - mouvement_relocalisation  (type : arrivage, transfert, sortie, ajustement ; date_mouvement)
     *
     * Le tableau de bord lisait d'anciennes tables (alertes_stock, mouvement_inventaire) restées vides, et
     * comptait les produits désactivés : « 0 alerte », « 0 mouvement ce mois », « 0 secteur ».
     */
    public function getData(int $tenantId, Carbon $dateFrom, Carbon $dateTo): array
    {
        return [
            'produits' => $this->produits($tenantId),
            'mouvements' => $this->mouvements($tenantId, $dateFrom, $dateTo),
            'secteurs' => $this->actifs('secteurs', $tenantId),
            'employes_actifs' => $this->actifs('employes', $tenantId),
        ];
    }

    // -------------------------------------------------------------------------
    // Produits actifs ; alertes et ruptures parmi les produits suivis (seuil d'alerte > 0)
    // -------------------------------------------------------------------------
    private function produits(int $tenantId): array
    {
        $produitsActifs = fn () => DB::table('produits')
            ->where('produits.tenant_id', $tenantId)
            ->whereNull('produits.deleted_at')
            ->where('produits.actif', true);

        $stocks = DB::table('inventaire_scan')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->selectRaw('numero, SUM(quantite) AS stock')
            ->groupBy('numero');

        $suivis = $produitsActifs()
            ->where('produits.seuil_alerte', '>', 0)
            ->leftJoinSub($stocks, 'stocks', 'stocks.numero', '=', 'produits.numero')
            ->get(['produits.seuil_alerte', DB::raw('COALESCE(stocks.stock, 0) AS stock')]);

        return [
            'total' => $produitsActifs()->count(),
            'en_alerte' => $suivis->filter(fn ($p) => (float) $p->stock < (float) $p->seuil_alerte)->count(),
            'rupture' => $suivis->filter(fn ($p) => (float) $p->stock <= 0)->count(),
        ];
    }

    // -------------------------------------------------------------------------
    // Mouvements de la période (relocalisation : arrivages = entrées, sorties)
    // -------------------------------------------------------------------------
    private function mouvements(int $tenantId, Carbon $dateFrom, Carbon $dateTo): array
    {
        $r = DB::table('mouvement_relocalisation')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereBetween('date_mouvement', [$dateFrom, $dateTo])
            ->selectRaw("COUNT(*) AS total_periode,
                SUM(CASE WHEN type = 'arrivage' THEN 1 ELSE 0 END) AS entrees,
                SUM(CASE WHEN type = 'sortie' THEN 1 ELSE 0 END) AS sorties")
            ->first();

        return [
            'total_periode' => (int) ($r->total_periode ?? 0),
            'entrees' => (int) ($r->entrees ?? 0),
            'sorties' => (int) ($r->sorties ?? 0),
        ];
    }

    /** Lignes actives d'une table de l'entreprise (secteurs, employés). */
    private function actifs(string $table, int $tenantId): int
    {
        return DB::table($table)->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('actif', true)->count();
    }
}
