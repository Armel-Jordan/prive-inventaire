<?php

namespace App\Rapports;

use App\Models\Client;
use App\Models\Facture;

/** Clients actifs sans facture depuis un nombre de jours (ou jamais facturés), avec leur chiffre d'affaires des douze derniers mois. */
class ClientsInactifs extends Rapport
{
    public function code(): string
    {
        return 'clients-inactifs';
    }

    public function categorie(): string
    {
        return 'clients';
    }

    public function module(): string
    {
        return 'clients';
    }

    public function titre(): array
    {
        return ['fr' => 'Clients sans achat récent', 'en' => 'Customers with no recent purchase'];
    }

    public function description(): array
    {
        return ['fr' => "Les clients actifs sans facture depuis le nombre de jours choisi (ou jamais facturés), avec leur dernière facture et leur chiffre d'affaires des douze derniers mois : ceux à relancer.",
            'en' => 'Active customers with no invoice for the chosen number of days (or never invoiced), with their last invoice and revenue over the last twelve months: those to follow up.'];
    }

    public function parametres(): array
    {
        return [
            'jours' => ['type' => 'entier', 'libelle' => ['fr' => 'Sans facture depuis (jours)', 'en' => 'No invoice for (days)'], 'defaut' => 90, 'min' => 1, 'max' => 3650],
        ];
    }

    public function colonnes(array $valeurs): array
    {
        return [
            'code' => ['libelle' => ['fr' => 'Code', 'en' => 'Code'], 'type' => 'texte'],
            'client' => ['libelle' => ['fr' => 'Client', 'en' => 'Customer'], 'type' => 'texte'],
            'ville' => ['libelle' => ['fr' => 'Ville', 'en' => 'City'], 'type' => 'texte'],
            'telephone' => ['libelle' => ['fr' => 'Téléphone', 'en' => 'Phone'], 'type' => 'texte'],
            'derniere_facture' => ['libelle' => ['fr' => 'Dernière facture', 'en' => 'Last invoice'], 'type' => 'date'],
            'jours' => ['libelle' => ['fr' => 'Jours', 'en' => 'Days'], 'type' => 'entier'],
            'chiffre_affaires' => ['libelle' => ['fr' => "Chiffre d'affaires 12 mois", 'en' => '12-month revenue'], 'type' => 'montant', 'total' => true],
        ];
    }

    public function lignes(array $valeurs): array
    {
        $emises = RapportVentes::FACTURES_EMISES;
        $dernieres = Facture::query()->whereIn('statut', $emises)->groupBy('client_id')->selectRaw('client_id, MAX(date_facture) AS derniere')->pluck('derniere', 'client_id');
        $annee = Facture::query()->whereIn('statut', $emises)->whereDate('date_facture', '>=', today()->subYear()->toDateString())
            ->groupBy('client_id')->selectRaw('client_id, SUM(montant_ht * taux_change) AS total')->pluck('total', 'client_id');
        $limite = today()->subDays($valeurs['jours'])->toDateString();

        return Client::query()->where('actif', true)->where('de_passage', false)->orderBy('raison_sociale')->get(['id', 'code', 'raison_sociale', 'ville', 'telephone'])
            ->filter(fn (Client $c) => ! isset($dernieres[$c->id]) || substr((string) $dernieres[$c->id], 0, 10) < $limite)
            ->map(fn (Client $c) => [
                'code' => $c->code,
                'client' => $c->raison_sociale,
                'ville' => $c->ville,
                'telephone' => $c->telephone,
                'derniere_facture' => isset($dernieres[$c->id]) ? substr((string) $dernieres[$c->id], 0, 10) : null,
                'jours' => isset($dernieres[$c->id]) ? $this->joursDepuis($dernieres[$c->id]) : null,
                'chiffre_affaires' => $this->argent($annee[$c->id] ?? 0),
            ])
            ->sortByDesc('chiffre_affaires')->values()->all();
    }
}
