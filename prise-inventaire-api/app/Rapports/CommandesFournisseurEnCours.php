<?php

namespace App\Rapports;

use App\Models\ComFourEntete;

/** Commandes fournisseur en cours (envoyées ou reçues en partie) : part reçue et retard sur la date prévue. */
class CommandesFournisseurEnCours extends Rapport
{
    public function code(): string
    {
        return 'commandes-fournisseur-en-cours';
    }

    public function categorie(): string
    {
        return 'achats';
    }

    public function module(): string
    {
        return 'commandes_fournisseur';
    }

    public function titre(): array
    {
        return ['fr' => 'Commandes fournisseur en cours', 'en' => 'Open purchase orders'];
    }

    public function description(): array
    {
        return ['fr' => 'Les commandes envoyées ou reçues en partie : part reçue, date de livraison prévue et retard, les plus en retard d’abord.',
            'en' => 'Orders sent or partly received: share received, expected delivery date and delay, most overdue first.'];
    }

    public function parametres(): array
    {
        return [
            'fournisseur_id' => ['type' => 'fournisseur', 'libelle' => ['fr' => 'Fournisseur', 'en' => 'Supplier']],
        ];
    }

    public function colonnes(array $valeurs): array
    {
        return [
            'numero' => ['libelle' => ['fr' => 'Commande', 'en' => 'Order'], 'type' => 'texte'],
            'fournisseur' => ['libelle' => ['fr' => 'Fournisseur', 'en' => 'Supplier'], 'type' => 'texte'],
            'date_commande' => ['libelle' => ['fr' => 'Date', 'en' => 'Date'], 'type' => 'date'],
            'date_prevue' => ['libelle' => ['fr' => 'Livraison prévue', 'en' => 'Expected'], 'type' => 'date'],
            'retard' => ['libelle' => ['fr' => 'Retard (jours)', 'en' => 'Delay (days)'], 'type' => 'entier'],
            'part_recue' => ['libelle' => ['fr' => 'Part reçue', 'en' => 'Share received'], 'type' => 'pourcentage'],
            'montant' => ['libelle' => ['fr' => 'Montant', 'en' => 'Amount'], 'type' => 'montant', 'total' => true],
        ];
    }

    public function lignes(array $valeurs): array
    {
        return ComFourEntete::query()->with(['fournisseur:id,raison_sociale', 'lignes:id,com_four_entete_id,quantite_commandee,quantite_recue'])
            ->whereIn('statut', [ComFourEntete::STATUT_ENVOYEE, ComFourEntete::STATUT_PARTIELLE])
            ->when($valeurs['fournisseur_id'], fn ($q, $fournisseur) => $q->where('fournisseur_id', $fournisseur))
            ->get()
            ->map(function (ComFourEntete $c) {
                $commandee = (int) $c->lignes->sum('quantite_commandee');
                $retard = $c->date_livraison_prevue ? $this->joursDepuis($c->date_livraison_prevue) : null;

                return [
                    'numero' => $c->numero,
                    'fournisseur' => $c->fournisseur?->raison_sociale ?? '',
                    'date_commande' => $c->date_commande?->toDateString(),
                    'date_prevue' => $c->date_livraison_prevue?->toDateString(),
                    'retard' => $retard !== null && $retard > 0 ? $retard : null,
                    'part_recue' => $commandee > 0 ? round(100 * (int) $c->lignes->sum('quantite_recue') / $commandee, 1) : null,
                    'montant' => $this->argent((float) $c->montant_total * (float) ($c->taux_change ?: 1)),
                ];
            })
            ->sortByDesc(fn (array $l) => $l['retard'] ?? -1)->values()->all();
    }
}
