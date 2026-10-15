<?php

namespace App\Services;

use App\Models\GroupeTaxe;

/**
 * Calcule les taxes d'un groupe sur un montant HT : chaque taxe arrondie au centime,
 * dans l'ordre du groupe, selon ce à quoi elle s'applique (HT, HT + taxes précédentes,
 * taxe précédente).
 */
class CalculTaxes
{
    /**
     * @return array{montant_ht: float, taxes: list<array{taxe_id: int, nom: string, taux: float, applique_sur: string, base: float, montant: float}>, total_taxes: float, montant_ttc: float, taux_effectif: float}
     */
    public function calculer(GroupeTaxe $groupe, float $montantHt): array
    {
        $groupe->loadMissing('lignes.taxe');
        $taxes = [];
        $total = 0.0;
        $precedente = 0.0;

        foreach ($groupe->lignes as $ligne) {
            $base = $this->base($ligne->applique_sur, $montantHt, $total, $precedente);
            $precedente = self::arrondir($base * (float) $ligne->taxe->taux / 100);
            $total += $precedente;

            $taxes[] = [
                'taxe_id' => $ligne->tenant_taxe_id,
                'nom' => $ligne->taxe->nom,
                'taux' => (float) $ligne->taxe->taux,
                'applique_sur' => $ligne->applique_sur,
                'base' => self::arrondir($base),
                'montant' => $precedente,
            ];
        }

        return [
            'montant_ht' => self::arrondir($montantHt),
            'taxes' => $taxes,
            'total_taxes' => self::arrondir($total),
            'montant_ttc' => self::arrondir($montantHt + $total),
            'taux_effectif' => $this->tauxEffectif($groupe),
        ];
    }

    /** Taux global équivalent du groupe (ex. TVA 17,5 % + CAC 10 % de la TVA = 19,25 %), sans arrondi intermédiaire. */
    public function tauxEffectif(GroupeTaxe $groupe): float
    {
        $groupe->loadMissing('lignes.taxe');
        $total = 0.0;
        $precedente = 0.0;

        foreach ($groupe->lignes as $ligne) {
            $precedente = $this->base($ligne->applique_sur, 100.0, $total, $precedente) * (float) $ligne->taxe->taux / 100;
            $total += $precedente;
        }

        return round($total, 4);
    }

    private function base(string $appliqueSur, float $montantHt, float $taxesPrecedentes, float $taxePrecedente): float
    {
        return match ($appliqueSur) {
            'base_et_taxes_precedentes' => $montantHt + $taxesPrecedentes,
            'taxe_precedente' => $taxePrecedente,
            default => $montantHt,
        };
    }

    /** Arrondi au centime « demi vers le haut », insensible aux erreurs de représentation (9,975 → 9,98). */
    public static function arrondir(float $valeur): float
    {
        return round($valeur + ($valeur >= 0 ? 1e-9 : -1e-9), 2);
    }
}
