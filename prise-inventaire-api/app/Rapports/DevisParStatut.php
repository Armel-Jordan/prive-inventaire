<?php

namespace App\Rapports;

use App\Models\Devis;

/** Devis de la période par statut : nombre, montant et part, avec le taux de transformation des devis envoyés. */
class DevisParStatut extends Rapport
{
    public function code(): string
    {
        return 'devis-par-statut';
    }

    public function categorie(): string
    {
        return 'ventes';
    }

    public function module(): string
    {
        return 'devis';
    }

    public function titre(): array
    {
        return ['fr' => 'Devis et transformation', 'en' => 'Quotes and conversion'];
    }

    public function description(): array
    {
        return ['fr' => 'Les devis de la période par statut (brouillon, envoyé, accepté, refusé, expiré) : nombre, montant et part. La part des acceptés parmi les devis sortis donne le taux de transformation.',
            'en' => 'Quotes of the period by status (draft, sent, accepted, declined, expired): count, amount and share. The share of accepted quotes among those sent gives the conversion rate.'];
    }

    public function parametres(): array
    {
        return $this->periode();
    }

    public function colonnes(array $valeurs): array
    {
        return [
            'statut' => ['libelle' => ['fr' => 'Statut', 'en' => 'Status'], 'type' => 'texte'],
            'devis' => ['libelle' => ['fr' => 'Devis', 'en' => 'Quotes'], 'type' => 'entier', 'total' => true],
            'montant' => ['libelle' => ['fr' => 'Montant', 'en' => 'Amount'], 'type' => 'montant', 'total' => true],
            'part' => ['libelle' => ['fr' => 'Part des devis sortis', 'en' => 'Share of quotes sent'], 'type' => 'pourcentage'],
        ];
    }

    public function lignes(array $valeurs): array
    {
        $libelles = ['brouillon' => ['fr' => 'Brouillon', 'en' => 'Draft'], 'envoye' => ['fr' => 'Envoyé', 'en' => 'Sent'], 'accepte' => ['fr' => 'Accepté', 'en' => 'Accepted'],
            'refuse' => ['fr' => 'Refusé', 'en' => 'Declined'], 'expire' => ['fr' => 'Expiré', 'en' => 'Expired']];
        [$nombres, $montants] = [[], []];
        foreach (Devis::query()->whereDate('date_devis', '>=', $valeurs['du'])->whereDate('date_devis', '<=', $valeurs['au'])
            ->groupBy('statut')->selectRaw('statut, COUNT(*) AS nombre, SUM(montant_total * COALESCE(taux_change, 1)) AS montant')->get() as $groupe) {
            $nombres[$groupe->statut] = (int) $groupe->nombre;
            $montants[$groupe->statut] = (float) $groupe->montant;
        }
        // Devis sortis : ceux qui ont quitté le brouillon.
        $sortis = array_sum(array_diff_key($nombres, ['brouillon' => true]));

        return collect($libelles)->map(fn (array $libelle, string $statut) => [
            'statut' => $this->texte($libelle),
            'devis' => $nombres[$statut] ?? 0,
            'montant' => $this->argent($montants[$statut] ?? 0),
            'part' => $statut === 'brouillon' || $sortis === 0 ? null : round(100 * ($nombres[$statut] ?? 0) / $sortis, 1),
        ])->values()->all();
    }
}
