<?php

namespace App\Rapports;

use App\Models\Facture;
use Illuminate\Support\Carbon;

/** Ventes par jour, semaine ou mois : nombre de factures, hors taxes, taxes, toutes taxes, crédité. */
class VentesParPeriode extends RapportVentes
{
    public function code(): string
    {
        return 'ventes-par-periode';
    }

    public function titre(): array
    {
        return ['fr' => 'Ventes par période', 'en' => 'Sales by period'];
    }

    public function description(): array
    {
        return ['fr' => 'Les factures émises, regroupées par jour, semaine ou mois : nombre, hors taxes, taxes, toutes taxes et crédité.',
            'en' => 'Issued invoices grouped by day, week or month: count, excluding tax, tax, including tax and credited.'];
    }

    public function parametres(): array
    {
        return $this->periode() + [
            'regroupement' => ['type' => 'choix', 'libelle' => ['fr' => 'Regroupement', 'en' => 'Group by'], 'defaut' => 'jour', 'options' => [
                'jour' => ['fr' => 'Jour', 'en' => 'Day'], 'semaine' => ['fr' => 'Semaine', 'en' => 'Week'], 'mois' => ['fr' => 'Mois', 'en' => 'Month'],
            ]],
        ];
    }

    public function colonnes(array $valeurs): array
    {
        return [
            'periode' => ['libelle' => ['fr' => 'Période', 'en' => 'Period'], 'type' => 'texte'],
            'factures' => ['libelle' => ['fr' => 'Factures', 'en' => 'Invoices'], 'type' => 'entier', 'total' => true],
            'montant_ht' => ['libelle' => ['fr' => 'Hors taxes', 'en' => 'Excl. tax'], 'type' => 'montant', 'total' => true],
            'montant_tva' => ['libelle' => ['fr' => 'Taxes', 'en' => 'Tax'], 'type' => 'montant', 'total' => true],
            'montant_ttc' => ['libelle' => ['fr' => 'Toutes taxes', 'en' => 'Incl. tax'], 'type' => 'montant', 'total' => true],
            'montant_credite' => ['libelle' => ['fr' => 'Crédité', 'en' => 'Credited'], 'type' => 'montant', 'total' => true],
        ];
    }

    public function lignes(array $valeurs): array
    {
        $groupes = [];
        foreach ($this->factures($valeurs)->orderBy('date_facture')->get(['id', 'date_facture', 'montant_ht', 'montant_tva', 'montant_ttc', 'montant_credite', 'taux_change']) as $facture) {
            $cle = $this->periodeDe($facture, $valeurs['regroupement']);
            $taux = (float) ($facture->taux_change ?: 1);
            $groupe = $groupes[$cle] ?? ['periode' => $cle, 'factures' => 0, 'montant_ht' => 0.0, 'montant_tva' => 0.0, 'montant_ttc' => 0.0, 'montant_credite' => 0.0];
            $groupe['factures']++;
            foreach (['montant_ht', 'montant_tva', 'montant_ttc', 'montant_credite'] as $champ) {
                $groupe[$champ] += (float) $facture->{$champ} * $taux;
            }
            $groupes[$cle] = $groupe;
        }

        return array_values(array_map(fn (array $g) => array_map(fn ($v) => is_float($v) ? $this->argent($v) : $v, $g), $groupes));
    }

    /** Libellé de la période d'une facture : 2026-10-17, 2026-S42 (semaine ISO ; 2026-W42 en anglais), 2026-10. */
    private function periodeDe(Facture $facture, string $regroupement): string
    {
        $date = Carbon::parse($facture->date_facture);

        return match ($regroupement) {
            'mois' => $date->format('Y-m'),
            'semaine' => $date->format('o').($this->langue === 'en' ? '-W' : '-S').$date->format('W'),
            default => $date->toDateString(),
        };
    }
}
