<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;

class DashboardService
{
    public function __construct(
        protected VentesDashboardService $ventes,
        protected AchatsDashboardService $achats,
        protected InventaireDashboardService $inventaire,
        protected FinanceDashboardService $finance,
    ) {}

    /**
     * Résout la période et retourne [dateFrom, dateTo, label].
     */
    public function resolvePeriod(string $period = 'month'): array
    {
        $now = Carbon::now();

        return match ($period) {
            'today' => [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
                'today',
            ],
            'week' => [
                $now->copy()->startOfWeek(),
                $now->copy()->endOfWeek(),
                'week',
            ],
            'year' => [
                $now->copy()->startOfYear(),
                $now->copy()->endOfYear(),
                'year',
            ],
            default => [
                $now->copy()->startOfMonth(),
                $now->copy()->endOfMonth(),
                'month',
            ],
        };
    }

    /**
     * Agrège toutes les sections du dashboard pour un tenant.
     */
    public function getData(int $tenantId, string $period = 'month'): array
    {
        [$dateFrom, $dateTo, $label] = $this->resolvePeriod($period);

        $ventesData    = $this->ventes->getData($tenantId, $dateFrom, $dateTo);
        $achatsData    = $this->achats->getData($tenantId, $dateFrom, $dateTo);
        $inventaireData = $this->inventaire->getData($tenantId, $dateFrom, $dateTo);
        $financeData   = $this->finance->getData($tenantId, $dateFrom, $dateTo);

        return [
            'ventes'     => $ventesData,
            'achats'     => $achatsData,
            'inventaire' => $inventaireData,
            'finance'    => $financeData,
            'meta'       => [
                'period'    => $label,
                'date_from' => $dateFrom->toDateString(),
                'date_to'   => $dateTo->toDateString(),
            ],
        ];
    }
}
