<?php

namespace App\Rapports;

use App\Models\Facture;
use App\Models\FacturePaiement;

/** Paiements reçus des clients sur la période, par mode de paiement : nombre, montant et part. */
class EncaissementsParMode extends Rapport
{
    public function code(): string
    {
        return 'encaissements-par-mode';
    }

    public function categorie(): string
    {
        return 'finance';
    }

    public function module(): string
    {
        return 'encaissements';
    }

    public function titre(): array
    {
        return ['fr' => 'Paiements reçus par mode', 'en' => 'Payments received by method'];
    }

    public function description(): array
    {
        return ['fr' => 'Les paiements des factures reçus sur la période, par mode (espèces, carte, virement…) : nombre, montant en devise de l’entreprise et part.',
            'en' => 'Invoice payments received during the period, by method (cash, card, transfer…): count, amount in the company currency and share.'];
    }

    public function parametres(): array
    {
        return $this->periode();
    }

    public function colonnes(array $valeurs): array
    {
        return [
            'mode' => ['libelle' => ['fr' => 'Mode', 'en' => 'Method'], 'type' => 'texte'],
            'paiements' => ['libelle' => ['fr' => 'Paiements', 'en' => 'Payments'], 'type' => 'entier', 'total' => true],
            'montant' => ['libelle' => ['fr' => 'Montant', 'en' => 'Amount'], 'type' => 'montant', 'total' => true],
            'part' => ['libelle' => ['fr' => 'Part', 'en' => 'Share'], 'type' => 'pourcentage', 'total' => true],
        ];
    }

    public function lignes(array $valeurs): array
    {
        $paiements = FacturePaiement::query()->join('factures', 'factures.id', '=', 'facture_paiements.facture_id')
            ->whereIn('facture_paiements.facture_id', Facture::query()->select('id'))
            ->whereDate('facture_paiements.date_paiement', '>=', $valeurs['du'])->whereDate('facture_paiements.date_paiement', '<=', $valeurs['au'])
            ->groupBy('facture_paiements.mode_paiement')
            ->selectRaw('facture_paiements.mode_paiement AS mode, COUNT(*) AS paiements, SUM(facture_paiements.montant * COALESCE(factures.taux_change, 1)) AS montant')
            ->get();
        $total = (float) $paiements->sum('montant');
        $modes = [
            'especes' => ['fr' => 'Espèces', 'en' => 'Cash'], 'cheque' => ['fr' => 'Chèque', 'en' => 'Cheque'], 'virement' => ['fr' => 'Virement', 'en' => 'Transfer'],
            'carte' => ['fr' => 'Carte', 'en' => 'Card'], 'carte_cadeau' => ['fr' => 'Carte cadeau', 'en' => 'Gift card'], 'autre' => ['fr' => 'Autre', 'en' => 'Other'],
        ];

        return $paiements->map(fn ($p) => [
            'mode' => isset($modes[$p->mode]) ? $this->texte($modes[$p->mode]) : (string) ($p->mode ?: '—'),
            'paiements' => (int) $p->paiements,
            'montant' => $this->argent($p->montant),
            'part' => $total > 0 ? round(100 * (float) $p->montant / $total, 1) : 0.0,
        ])->sortByDesc('montant')->values()->all();
    }
}
