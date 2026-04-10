<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AchatsDashboardService
{
    /**
     * Agrège les commandes fournisseurs.
     *
     * Table : com_four_entete
     * Statuts : brouillon, envoyee, partielle, complete, annulee
     */
    public function getData(int $tenantId, Carbon $dateFrom, Carbon $dateTo): array
    {
        return [
            'commandes_fournisseurs' => $this->commandesFournisseurs($tenantId, $dateFrom, $dateTo),
        ];
    }

    private function commandesFournisseurs(int $tenantId, Carbon $dateFrom, Carbon $dateTo): array
    {
        $r = DB::selectOne(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN statut IN (\'brouillon\', \'envoyee\', \'partielle\') THEN 1 ELSE 0 END) AS en_attente,
                COALESCE(SUM(montant_total), 0) AS montant_total
             FROM com_four_entete
             WHERE tenant_id = ?
               AND created_at BETWEEN ? AND ?',
            [$tenantId, $dateFrom, $dateTo]
        );

        return [
            'total'         => (int)   ($r->total         ?? 0),
            'en_attente'    => (int)   ($r->en_attente    ?? 0),
            'montant_total' => number_format((float) ($r->montant_total ?? 0), 2, '.', ''),
        ];
    }
}
