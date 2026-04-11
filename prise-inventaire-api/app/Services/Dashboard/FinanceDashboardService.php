<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FinanceDashboardService
{
    /**
     * Calcule les indicateurs financiers clés pour la période.
     *
     * - Chiffre d'affaires   : somme montant_ttc des factures émises/payées de la période
     * - Encaissé             : somme des paiements reçus (facture_paiements) de la période
     * - Montant impayé       : reste_a_payer cumulé sur factures émises / partiellement_payées
     * - Dépenses achats      : montant_total des commandes fournisseurs (non annulées) de la période
     * - Marge brute          : CA - Dépenses achats
     */
    public function getData(int $tenantId, Carbon $dateFrom, Carbon $dateTo): array
    {
        $ca = $this->chiffreAffaires($tenantId, $dateFrom, $dateTo);
        $encaisse = $this->encaisse($tenantId, $dateFrom, $dateTo);
        $impaye = $this->montantImpaye($tenantId, $dateFrom, $dateTo);
        $depenses = $this->depensesAchats($tenantId, $dateFrom, $dateTo);
        $margeBrute = $ca - $depenses;

        return [
            'chiffre_affaires' => number_format($ca, 2, '.', ''),
            'encaisse' => number_format($encaisse, 2, '.', ''),
            'montant_impaye' => number_format($impaye, 2, '.', ''),
            'depenses_achats' => number_format($depenses, 2, '.', ''),
            'marge_brute' => number_format($margeBrute, 2, '.', ''),
        ];
    }

    // -------------------------------------------------------------------------
    // CA = factures émises ou payées créées dans la période
    // -------------------------------------------------------------------------
    private function chiffreAffaires(int $tenantId, Carbon $dateFrom, Carbon $dateTo): float
    {
        $r = DB::selectOne(
            'SELECT COALESCE(SUM(montant_ttc), 0) AS ca
             FROM factures
             WHERE tenant_id = ?
               AND statut IN (\'emise\', \'partiellement_payee\', \'payee\')
               AND created_at BETWEEN ? AND ?',
            [$tenantId, $dateFrom, $dateTo]
        );

        return (float) ($r->ca ?? 0);
    }

    // -------------------------------------------------------------------------
    // Encaissé = paiements enregistrés dans la période (toutes factures)
    // On joint facture_paiements → factures pour filtrer par tenant_id
    // -------------------------------------------------------------------------
    private function encaisse(int $tenantId, Carbon $dateFrom, Carbon $dateTo): float
    {
        $r = DB::selectOne(
            'SELECT COALESCE(SUM(fp.montant), 0) AS total_encaisse
             FROM facture_paiements fp
             INNER JOIN factures f ON f.id = fp.facture_id
             WHERE f.tenant_id = ?
               AND fp.created_at BETWEEN ? AND ?',
            [$tenantId, $dateFrom, $dateTo]
        );

        return (float) ($r->total_encaisse ?? 0);
    }

    // -------------------------------------------------------------------------
    // Impayé = cumul reste_a_payer sur toutes factures impayées du tenant
    // (pas limité à la période — c'est un état global)
    // -------------------------------------------------------------------------
    private function montantImpaye(int $tenantId, Carbon $dateFrom, Carbon $dateTo): float
    {
        $r = DB::selectOne(
            'SELECT COALESCE(SUM(reste_a_payer), 0) AS total_impaye
             FROM factures
             WHERE tenant_id = ?
               AND statut IN (\'emise\', \'partiellement_payee\')',
            [$tenantId]
        );

        return (float) ($r->total_impaye ?? 0);
    }

    // -------------------------------------------------------------------------
    // Dépenses achats = commandes fournisseurs non annulées de la période
    // -------------------------------------------------------------------------
    private function depensesAchats(int $tenantId, Carbon $dateFrom, Carbon $dateTo): float
    {
        $r = DB::selectOne(
            'SELECT COALESCE(SUM(montant_total), 0) AS total_depenses
             FROM com_four_entete
             WHERE tenant_id = ?
               AND statut != \'annulee\'
               AND created_at BETWEEN ? AND ?',
            [$tenantId, $dateFrom, $dateTo]
        );

        return (float) ($r->total_depenses ?? 0);
    }
}
