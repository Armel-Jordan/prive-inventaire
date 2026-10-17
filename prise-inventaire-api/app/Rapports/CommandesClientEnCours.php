<?php

namespace App\Rapports;

use App\Models\ComClientEntete;

/** Commandes client en cours (en attente ou acceptées, pas encore facturées), de la plus ancienne à la plus récente. */
class CommandesClientEnCours extends Rapport
{
    public function code(): string
    {
        return 'commandes-client-en-cours';
    }

    public function categorie(): string
    {
        return 'ventes';
    }

    public function module(): string
    {
        return 'commandes_client';
    }

    public function titre(): array
    {
        return ['fr' => 'Commandes client en cours', 'en' => 'Open customer orders'];
    }

    public function description(): array
    {
        return ['fr' => 'Les commandes en attente ou acceptées, pas encore facturées, de la plus ancienne à la plus récente, avec leur âge et la date souhaitée.',
            'en' => 'Orders pending or accepted, not yet invoiced, oldest first, with their age and requested date.'];
    }

    public function parametres(): array
    {
        return [
            'client_id' => ['type' => 'client', 'libelle' => ['fr' => 'Client', 'en' => 'Customer']],
        ];
    }

    public function colonnes(array $valeurs): array
    {
        return [
            'numero' => ['libelle' => ['fr' => 'Commande', 'en' => 'Order'], 'type' => 'texte'],
            'client' => ['libelle' => ['fr' => 'Client', 'en' => 'Customer'], 'type' => 'texte'],
            'statut' => ['libelle' => ['fr' => 'Statut', 'en' => 'Status'], 'type' => 'texte'],
            'date_commande' => ['libelle' => ['fr' => 'Date', 'en' => 'Date'], 'type' => 'date'],
            'date_souhaitee' => ['libelle' => ['fr' => 'Souhaitée le', 'en' => 'Requested for'], 'type' => 'date'],
            'jours' => ['libelle' => ['fr' => 'Âge (jours)', 'en' => 'Age (days)'], 'type' => 'entier'],
            'montant_ttc' => ['libelle' => ['fr' => 'Montant TTC', 'en' => 'Amount incl. tax'], 'type' => 'montant', 'total' => true],
        ];
    }

    public function lignes(array $valeurs): array
    {
        $statuts = ['en_attente' => ['fr' => 'En attente', 'en' => 'Pending'], 'acceptee' => ['fr' => 'Acceptée', 'en' => 'Accepted']];

        return ComClientEntete::query()->with('client:id,code,raison_sociale')
            ->whereIn('statut', array_keys($statuts))
            ->when($valeurs['client_id'], fn ($q, $client) => $q->where('client_id', $client))
            ->orderBy('date_commande')->orderBy('id')->get()
            ->map(fn (ComClientEntete $c) => [
                'numero' => $c->numero,
                'client' => $c->client?->raison_sociale ?? $c->passage_nom ?? '',
                'statut' => $this->texte($statuts[$c->statut]),
                'date_commande' => $c->date_commande?->toDateString(),
                'date_souhaitee' => $c->date_livraison_souhaitee?->toDateString(),
                'jours' => $this->joursDepuis($c->date_commande),
                'montant_ttc' => $this->argent((float) $c->montant_ttc * (float) ($c->taux_change ?: 1)),
            ])->values()->all();
    }
}
