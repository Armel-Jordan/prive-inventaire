<?php

namespace App\Rapports;

use App\Models\Avoir;
use App\Models\Representant;

/**
 * Ventes par représentant (plan de parité fripv2, phase 1.11a) : les factures émises de la période par représentant,
 * moins les notes de crédit émises dans la période sur leurs factures ; ventes nettes hors taxes et commission, au taux
 * figé sur chaque facture. Les factures sans représentant forment une ligne à part.
 */
class VentesParRepresentant extends RapportVentes
{
    public function code(): string
    {
        return 'ventes-par-representant';
    }

    public function titre(): array
    {
        return ['fr' => 'Ventes par représentant', 'en' => 'Sales by sales rep'];
    }

    public function description(): array
    {
        return ['fr' => 'Les factures émises de la période par représentant, moins les notes de crédit émises sur leurs factures : ventes nettes hors taxes et commission, au taux figé sur chaque facture.',
            'en' => 'Issued invoices of the period by sales rep, less the credit notes issued on their invoices: net sales excluding tax and commission, at the rate frozen on each invoice.'];
    }

    public function parametres(): array
    {
        return $this->periode() + [
            'representant' => ['type' => 'representant', 'libelle' => ['fr' => 'Représentant', 'en' => 'Sales rep']],
        ];
    }

    public function colonnes(array $valeurs): array
    {
        return [
            'representant' => ['libelle' => ['fr' => 'Représentant', 'en' => 'Sales rep'], 'type' => 'texte'],
            'factures' => ['libelle' => ['fr' => 'Factures', 'en' => 'Invoices'], 'type' => 'entier', 'total' => true],
            'ventes_ht' => ['libelle' => ['fr' => 'Ventes HT', 'en' => 'Sales excl. tax'], 'type' => 'montant', 'total' => true],
            'credits_ht' => ['libelle' => ['fr' => 'Notes de crédit HT', 'en' => 'Credit notes excl. tax'], 'type' => 'montant', 'total' => true],
            'ventes_nettes' => ['libelle' => ['fr' => 'Ventes nettes HT', 'en' => 'Net sales excl. tax'], 'type' => 'montant', 'total' => true],
            'commission' => ['libelle' => ['fr' => 'Commission', 'en' => 'Commission'], 'type' => 'montant', 'total' => true],
        ];
    }

    public function lignes(array $valeurs): array
    {
        $representant = $valeurs['representant'] ?? null;
        $ventes = $this->factures($valeurs)
            ->when($representant, fn ($q, $id) => $q->where('representant_id', $id))
            ->groupBy('representant_id')
            ->selectRaw('representant_id, COUNT(*) AS factures, SUM(montant_ht * taux_change) AS ventes_ht,
                SUM(montant_ht * taux_change * COALESCE(taux_commission, 0) / 100) AS commission')
            ->get()->keyBy(fn ($l) => (int) $l->representant_id);
        // Notes de crédit émises dans la période sur les factures d'un représentant : moins de ventes et de commission.
        $credits = Avoir::query()->whereIn('avoirs.statut', Avoir::EMISES)
            ->whereDate('avoirs.date_avoir', '>=', $valeurs['du'])->whereDate('avoirs.date_avoir', '<=', $valeurs['au'])
            ->join('factures', 'factures.id', '=', 'avoirs.facture_id')
            ->when($representant, fn ($q, $id) => $q->where('factures.representant_id', $id))
            ->groupBy('factures.representant_id')
            ->selectRaw('factures.representant_id AS representant_id, SUM(avoirs.montant_ht * avoirs.taux_change) AS credits_ht,
                SUM(avoirs.montant_ht * avoirs.taux_change * COALESCE(factures.taux_commission, 0) / 100) AS commission')
            ->get()->keyBy(fn ($l) => (int) $l->representant_id);

        $ids = $ventes->keys()->merge($credits->keys())->unique();
        $noms = Representant::withTrashed()->whereIn('id', $ids->filter()->all())->pluck('nom', 'id');
        $sansRepresentant = $this->texte(['fr' => '(Sans représentant)', 'en' => '(No sales rep)']);

        return $ids->map(function (int $id) use ($ventes, $credits, $noms, $sansRepresentant) {
            $vente = $ventes->get($id);
            $credit = $credits->get($id);
            $ventesHt = (float) ($vente->ventes_ht ?? 0);
            $creditsHt = (float) ($credit->credits_ht ?? 0);

            return [
                'representant' => $id === 0 ? $sansRepresentant : ($noms[$id] ?? (string) $id),
                'factures' => (int) ($vente->factures ?? 0),
                'ventes_ht' => $this->argent($ventesHt),
                'credits_ht' => $this->argent($creditsHt),
                'ventes_nettes' => $this->argent($ventesHt - $creditsHt),
                'commission' => $this->argent((float) ($vente->commission ?? 0) - (float) ($credit->commission ?? 0)),
            ];
        })
            // Les représentants par ventes nettes, la ligne « sans représentant » à la fin.
            ->sortBy([fn ($a, $b) => ($a['representant'] === $sansRepresentant) <=> ($b['representant'] === $sansRepresentant), fn ($a, $b) => $b['ventes_nettes'] <=> $a['ventes_nettes']])
            ->values()->all();
    }
}
