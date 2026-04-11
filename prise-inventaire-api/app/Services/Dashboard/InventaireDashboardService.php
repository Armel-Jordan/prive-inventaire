<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class InventaireDashboardService
{
    /**
     * Agrège produits (stock + alertes) et mouvements d'inventaire.
     *
     * Tables :
     *   - produits              (tenant_id, seuil_alerte, deleted_at)
     *   - alertes_stock         (tenant_id, statut)
     *   - mouvement_inventaire  (tenant_id, type_mouvement IN 'ENTREE','SORTIE','CORRECTION')
     */
    public function getData(int $tenantId, Carbon $dateFrom, Carbon $dateTo): array
    {
        return [
            'produits' => $this->produits($tenantId),
            'mouvements' => $this->mouvements($tenantId, $dateFrom, $dateTo),
        ];
    }

    // -------------------------------------------------------------------------
    // Produits — total et alertes actives configurées
    // alertes_stock stocke les seuils (seuil_min, seuil_critique, actif)
    // -------------------------------------------------------------------------
    private function produits(int $tenantId): array
    {
        // Total produits actifs du tenant
        $rTotal = DB::selectOne(
            'SELECT COUNT(*) AS total
             FROM produits
             WHERE tenant_id = ?
               AND deleted_at IS NULL',
            [$tenantId]
        );

        // Alertes configurées et actives (seuil_min > seuil_critique = critique/rupture)
        $rAlertes = DB::selectOne(
            'SELECT
                COUNT(*) AS en_alerte,
                SUM(CASE WHEN seuil_critique > 0 THEN 1 ELSE 0 END) AS rupture
             FROM alertes_stock
             WHERE tenant_id = ?
               AND actif = 1',
            [$tenantId]
        );

        return [
            'total' => (int) ($rTotal->total ?? 0),
            'en_alerte' => (int) ($rAlertes->en_alerte ?? 0),
            'rupture' => (int) ($rAlertes->rupture ?? 0),
        ];
    }

    // -------------------------------------------------------------------------
    // Mouvements de la période
    // Table : mouvement_inventaire (tenant_id ajouté par migration 2026_04_10_200001)
    // type_mouvement : ENTREE | SORTIE | CORRECTION
    // -------------------------------------------------------------------------
    private function mouvements(int $tenantId, Carbon $dateFrom, Carbon $dateTo): array
    {
        $r = DB::selectOne(
            'SELECT
                COUNT(*) AS total_periode,
                SUM(CASE WHEN type_mouvement = \'ENTREE\' THEN 1 ELSE 0 END) AS entrees,
                SUM(CASE WHEN type_mouvement = \'SORTIE\' THEN 1 ELSE 0 END) AS sorties
             FROM mouvement_inventaire
             WHERE tenant_id = ?
               AND created_at BETWEEN ? AND ?',
            [$tenantId, $dateFrom, $dateTo]
        );

        return [
            'total_periode' => (int) ($r->total_periode ?? 0),
            'entrees' => (int) ($r->entrees ?? 0),
            'sorties' => (int) ($r->sorties ?? 0),
        ];
    }
}
