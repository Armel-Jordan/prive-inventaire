<?php

namespace App\Rapports;

use App\Models\Client;

/** Ventes par client : nombre de factures, hors taxes, toutes taxes, payé et reste à payer, du plus gros client au plus petit. */
class VentesParClient extends RapportVentes
{
    public function code(): string
    {
        return 'ventes-par-client';
    }

    public function titre(): array
    {
        return ['fr' => 'Ventes par client', 'en' => 'Sales by customer'];
    }

    public function description(): array
    {
        return ['fr' => 'Les factures émises de la période par client, du plus gros au plus petit : hors taxes, toutes taxes, payé, reste à payer.',
            'en' => 'Issued invoices of the period by customer, largest first: excluding tax, including tax, paid, balance due.'];
    }

    public function parametres(): array
    {
        return $this->periode() + [
            'limite' => ['type' => 'entier', 'libelle' => ['fr' => 'Nombre de clients', 'en' => 'Number of customers'], 'defaut' => 50, 'min' => 1, 'max' => 1000],
        ];
    }

    public function colonnes(array $valeurs): array
    {
        return [
            'code' => ['libelle' => ['fr' => 'Code', 'en' => 'Code'], 'type' => 'texte'],
            'client' => ['libelle' => ['fr' => 'Client', 'en' => 'Customer'], 'type' => 'texte'],
            'factures' => ['libelle' => ['fr' => 'Factures', 'en' => 'Invoices'], 'type' => 'entier', 'total' => true],
            'montant_ht' => ['libelle' => ['fr' => 'Hors taxes', 'en' => 'Excl. tax'], 'type' => 'montant', 'total' => true],
            'montant_ttc' => ['libelle' => ['fr' => 'Toutes taxes', 'en' => 'Incl. tax'], 'type' => 'montant', 'total' => true],
            'montant_paye' => ['libelle' => ['fr' => 'Payé', 'en' => 'Paid'], 'type' => 'montant', 'total' => true],
            'reste_a_payer' => ['libelle' => ['fr' => 'Reste à payer', 'en' => 'Balance due'], 'type' => 'montant', 'total' => true],
        ];
    }

    public function lignes(array $valeurs): array
    {
        $totaux = $this->factures($valeurs)->groupBy('client_id')
            ->selectRaw('client_id, COUNT(*) AS factures, SUM(montant_ht * taux_change) AS montant_ht, SUM(montant_ttc * taux_change) AS montant_ttc,
                SUM(montant_paye * taux_change) AS montant_paye, SUM(reste_a_payer * taux_change) AS reste_a_payer')
            ->orderByDesc('montant_ttc')->limit($valeurs['limite'])->get();
        $clients = Client::withTrashed()->whereIn('id', $totaux->pluck('client_id'))->get(['id', 'code', 'raison_sociale'])->keyBy('id');

        return $totaux->map(fn ($t) => [
            'code' => $clients[$t->client_id]->code ?? '',
            'client' => $clients[$t->client_id]->raison_sociale ?? '',
            'factures' => (int) $t->factures,
            'montant_ht' => $this->argent($t->montant_ht),
            'montant_ttc' => $this->argent($t->montant_ttc),
            'montant_paye' => $this->argent($t->montant_paye),
            'reste_a_payer' => $this->argent($t->reste_a_payer),
        ])->values()->all();
    }
}
