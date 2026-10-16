<?php

namespace App\Services\Comptabilite;

use App\Models\CompteComptable;
use App\Models\EcritureComptable;
use Illuminate\Support\Collection;

/**
 * États financiers tirés des écritures : bilan à une date et compte de résultat sur une période. Les comptes sont
 * regroupés sous leur en-tête le plus proche (60 Achats, 62 Services extérieurs…), dans l'ordre du plan.
 */
class EtatsFinanciers
{
    public function __construct(private Soldes $soldes) {}

    /**
     * Bilan à une date : actif, capitaux propres et dettes, chaque compte à son solde cumulé. Le résultat des
     * exercices pas encore clôturés (produits − charges) figure dans les capitaux propres, ce qui équilibre le bilan.
     */
    public function bilan(string $date): array
    {
        $comptes = CompteComptable::orderBy('numero')->get();
        $mouvements = $this->soldes->mouvements(null, $date);
        $sections = ['actif' => collect(), 'capitaux_propres' => collect(), 'passif' => collect()];
        $resultat = 0.0;
        foreach ($comptes as $compte) {
            $solde = round(($mouvements[$compte->id]->debit ?? 0) - ($mouvements[$compte->id]->credit ?? 0), 2);
            if ($solde == 0) {
                continue;
            }
            if (in_array($compte->nature, ['produit', 'charge'], true)) {
                $resultat -= $solde;

                continue;
            }
            // Actif au débit, capitaux propres et dettes au crédit : un compte de sens contraire (amortissements) se déduit.
            $sections[$compte->nature]->push(['compte' => $compte, 'montant' => $compte->nature === 'actif' ? $solde : -$solde]);
        }

        $actif = $this->grouper($sections['actif'], $comptes);
        $capitaux = $this->grouper($sections['capitaux_propres'], $comptes);
        $resultat = round($resultat, 2);
        if ($resultat != 0) {
            $capitaux['groupes'][] = ['numero' => null, 'libelle' => 'Résultat non encore clôturé', 'montant' => $resultat, 'comptes' => []];
            $capitaux['total'] = round($capitaux['total'] + $resultat, 2);
        }
        $passif = $this->grouper($sections['passif'], $comptes);
        $totalPassif = round($capitaux['total'] + $passif['total'], 2);

        return [
            'date' => $date,
            'actif' => $actif,
            'capitaux_propres' => $capitaux,
            'passif' => $passif,
            'resultat_non_cloture' => $resultat,
            'total_actif' => $actif['total'],
            'total_passif' => $totalPassif,
            'equilibre' => $actif['total'] == $totalPassif,
        ];
    }

    /**
     * Compte de résultat entre deux dates : produits et charges de la période, hors écritures de clôture (qui
     * soldent ces comptes au dernier jour de l'exercice).
     */
    public function resultat(string $du, string $au): array
    {
        $comptes = CompteComptable::orderBy('numero')->get();
        $mouvements = $this->soldes->mouvements($du, $au, null, [EcritureComptable::CLOTURE]);
        $produits = collect();
        $charges = collect();
        foreach ($comptes->whereIn('nature', ['produit', 'charge']) as $compte) {
            $solde = round(($mouvements[$compte->id]->debit ?? 0) - ($mouvements[$compte->id]->credit ?? 0), 2);
            if ($solde == 0) {
                continue;
            }
            if ($compte->nature === 'produit') {
                $produits->push(['compte' => $compte, 'montant' => -$solde]);
            } else {
                $charges->push(['compte' => $compte, 'montant' => $solde]);
            }
        }
        $produits = $this->grouper($produits, $comptes);
        $charges = $this->grouper($charges, $comptes);

        return [
            'du' => $du,
            'au' => $au,
            'produits' => $produits,
            'charges' => $charges,
            'resultat' => round($produits['total'] - $charges['total'], 2),
        ];
    }

    /**
     * Comptes regroupés sous leur en-tête le plus proche (sinon dans « Autres comptes »), groupes et comptes dans
     * l'ordre des numéros.
     *
     * @param  Collection<int, array{compte: CompteComptable, montant: float}>  $lignes
     * @return array{groupes: list<array{numero: ?string, libelle: string, montant: float, comptes: list<array>}>, total: float}
     */
    private function grouper(Collection $lignes, Collection $comptes): array
    {
        $parId = $comptes->keyBy('id');
        $groupes = [];
        foreach ($lignes as $ligne) {
            $entete = $ligne['compte']->parent_id ? $parId[$ligne['compte']->parent_id] ?? null : null;
            while ($entete && $entete->type !== CompteComptable::EN_TETE) {
                $entete = $entete->parent_id ? $parId[$entete->parent_id] ?? null : null;
            }
            $cle = $entete?->numero ?? '~';
            $groupes[$cle] ??= ['numero' => $entete?->numero, 'libelle' => $entete?->libelle ?? 'Autres comptes', 'montant' => 0.0, 'comptes' => []];
            $groupes[$cle]['montant'] = round($groupes[$cle]['montant'] + $ligne['montant'], 2);
            $groupes[$cle]['comptes'][] = ['id' => $ligne['compte']->id, 'numero' => $ligne['compte']->numero, 'libelle' => $ligne['compte']->libelle, 'montant' => $ligne['montant']];
        }
        ksort($groupes, SORT_STRING);

        return ['groupes' => array_values($groupes), 'total' => round(array_sum(array_column($groupes, 'montant')), 2)];
    }
}
