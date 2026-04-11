<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class VentesDashboardService
{
    /**
     * Agrège devis, commandes clients, factures, bons de livraison et tournées.
     *
     * Tables :
     *   - devis               (statuts : brouillon, envoye, accepte, refuse, expire)
     *   - com_client_entete   (statuts : brouillon, en_attente, acceptee, refusee, facturee, annulee)
     *   - factures            (statuts : brouillon, emise, partiellement_payee, payee, annulee)
     *   - bons_livraison      (statuts : cree, en_preparation, pret, en_livraison, livre_complet, livre_partiel, annule)
     *   - tournees            (statuts : planifiee, en_cours, terminee, annulee)
     */
    public function getData(int $tenantId, Carbon $dateFrom, Carbon $dateTo): array
    {
        return [
            'devis' => $this->devis($tenantId, $dateFrom, $dateTo),
            'commandes_clients' => $this->commandesClients($tenantId, $dateFrom, $dateTo),
            'factures' => $this->factures($tenantId, $dateFrom, $dateTo),
            'bons_livraison' => $this->bonsLivraison($tenantId, $dateFrom, $dateTo),
            'tournees' => $this->tournees($tenantId, $dateFrom, $dateTo),
        ];
    }

    // -------------------------------------------------------------------------
    // Devis
    // -------------------------------------------------------------------------
    private function devis(int $tenantId, Carbon $dateFrom, Carbon $dateTo): array
    {
        $rows = DB::select(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN statut IN (\'brouillon\', \'envoye\') THEN 1 ELSE 0 END) AS en_attente,
                SUM(CASE WHEN statut = \'accepte\' THEN 1 ELSE 0 END) AS acceptes,
                SUM(CASE WHEN statut = \'refuse\'  THEN 1 ELSE 0 END) AS refuses
             FROM devis
             WHERE tenant_id = ?
               AND deleted_at IS NULL
               AND created_at BETWEEN ? AND ?',
            [$tenantId, $dateFrom, $dateTo]
        );

        $r = $rows[0] ?? null;

        return [
            'total' => (int) ($r->total ?? 0),
            'en_attente' => (int) ($r->en_attente ?? 0),
            'acceptes' => (int) ($r->acceptes ?? 0),
            'refuses' => (int) ($r->refuses ?? 0),
        ];
    }

    // -------------------------------------------------------------------------
    // Commandes clients
    // -------------------------------------------------------------------------
    private function commandesClients(int $tenantId, Carbon $dateFrom, Carbon $dateTo): array
    {
        $r = DB::selectOne(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN statut IN (\'brouillon\', \'en_attente\', \'acceptee\') THEN 1 ELSE 0 END) AS en_cours,
                COALESCE(SUM(montant_ttc), 0) AS montant_total
             FROM com_client_entete
             WHERE tenant_id = ?
               AND created_at BETWEEN ? AND ?',
            [$tenantId, $dateFrom, $dateTo]
        );

        return [
            'total' => (int) ($r->total ?? 0),
            'en_cours' => (int) ($r->en_cours ?? 0),
            'montant_total' => number_format((float) ($r->montant_total ?? 0), 2, '.', ''),
        ];
    }

    // -------------------------------------------------------------------------
    // Factures
    // -------------------------------------------------------------------------
    private function factures(int $tenantId, Carbon $dateFrom, Carbon $dateTo): array
    {
        $r = DB::selectOne(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN statut = \'payee\' THEN 1 ELSE 0 END) AS payees,
                SUM(CASE WHEN statut IN (\'emise\', \'partiellement_payee\') THEN 1 ELSE 0 END) AS impayees,
                SUM(CASE WHEN statut IN (\'emise\', \'partiellement_payee\')
                          AND date_echeance < CURDATE() THEN 1 ELSE 0 END) AS en_retard,
                COALESCE(SUM(CASE WHEN statut IN (\'emise\', \'partiellement_payee\')
                                  THEN reste_a_payer ELSE 0 END), 0) AS montant_impaye
             FROM factures
             WHERE tenant_id = ?
               AND created_at BETWEEN ? AND ?',
            [$tenantId, $dateFrom, $dateTo]
        );

        return [
            'total' => (int) ($r->total ?? 0),
            'payees' => (int) ($r->payees ?? 0),
            'impayees' => (int) ($r->impayees ?? 0),
            'en_retard' => (int) ($r->en_retard ?? 0),
            'montant_impaye' => number_format((float) ($r->montant_impaye ?? 0), 2, '.', ''),
        ];
    }

    // -------------------------------------------------------------------------
    // Bons de livraison
    // -------------------------------------------------------------------------
    private function bonsLivraison(int $tenantId, Carbon $dateFrom, Carbon $dateTo): array
    {
        $r = DB::selectOne(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN statut IN (\'cree\', \'en_preparation\', \'pret\') THEN 1 ELSE 0 END) AS en_attente,
                SUM(CASE WHEN statut IN (\'en_livraison\', \'livre_complet\', \'livre_partiel\') THEN 1 ELSE 0 END) AS expedies
             FROM bons_livraison
             WHERE tenant_id = ?
               AND created_at BETWEEN ? AND ?',
            [$tenantId, $dateFrom, $dateTo]
        );

        return [
            'total' => (int) ($r->total ?? 0),
            'en_attente' => (int) ($r->en_attente ?? 0),
            'expedies' => (int) ($r->expedies ?? 0),
        ];
    }

    // -------------------------------------------------------------------------
    // Tournées
    // -------------------------------------------------------------------------
    private function tournees(int $tenantId, Carbon $dateFrom, Carbon $dateTo): array
    {
        $r = DB::selectOne(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN statut = \'en_cours\' THEN 1 ELSE 0 END) AS en_cours
             FROM tournees
             WHERE tenant_id = ?
               AND created_at BETWEEN ? AND ?',
            [$tenantId, $dateFrom, $dateTo]
        );

        return [
            'total' => (int) ($r->total ?? 0),
            'en_cours' => (int) ($r->en_cours ?? 0),
        ];
    }
}
