<?php

namespace App\Rapports;

use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\ProduitTenant;
use App\Models\ScanTenant;

/** Produits dormants : du stock, mais aucune vente depuis un nombre de jours (ou jamais vendus). */
class ProduitsDormants extends Rapport
{
    public function code(): string
    {
        return 'produits-dormants';
    }

    public function categorie(): string
    {
        return 'inventaire';
    }

    public function module(): string
    {
        return 'inventaires';
    }

    public function titre(): array
    {
        return ['fr' => 'Produits dormants', 'en' => 'Slow-moving products'];
    }

    public function description(): array
    {
        return ['fr' => 'Les produits qui ont du stock mais aucune vente facturée depuis le nombre de jours choisi (ou jamais vendus) : du stock qui immobilise de l’argent.',
            'en' => 'Products with stock but no invoiced sale for the chosen number of days (or never sold): stock that ties up money.'];
    }

    public function parametres(): array
    {
        return [
            'jours' => ['type' => 'entier', 'libelle' => ['fr' => 'Sans vente depuis (jours)', 'en' => 'No sale for (days)'], 'defaut' => 90, 'min' => 1, 'max' => 3650],
        ];
    }

    public function colonnes(array $valeurs): array
    {
        return [
            'numero' => ['libelle' => ['fr' => 'Produit', 'en' => 'Product'], 'type' => 'texte'],
            'description' => ['libelle' => ['fr' => 'Description', 'en' => 'Description'], 'type' => 'texte'],
            'stock' => ['libelle' => ['fr' => 'Stock', 'en' => 'Stock'], 'type' => 'quantite', 'total' => true],
            'unite' => ['libelle' => ['fr' => 'Unité', 'en' => 'Unit'], 'type' => 'texte'],
            'derniere_vente' => ['libelle' => ['fr' => 'Dernière vente', 'en' => 'Last sale'], 'type' => 'date'],
            'jours' => ['libelle' => ['fr' => 'Jours', 'en' => 'Days'], 'type' => 'entier'],
        ];
    }

    public function lignes(array $valeurs): array
    {
        $produits = ProduitTenant::query()->where('actif', true)->orderBy('numero')->get(['id', 'numero', 'description', 'mesure']);
        $stocks = ScanTenant::query()->whereIn('numero', $produits->pluck('numero'))->groupBy('numero')
            ->selectRaw('numero, SUM(quantite) AS quantite')->pluck('quantite', 'numero');
        $ventes = FactureLigne::query()->join('factures', 'factures.id', '=', 'facture_lignes.facture_id')
            ->whereIn('facture_lignes.facture_id', Facture::query()->whereIn('statut', RapportVentes::FACTURES_EMISES)->select('id'))
            ->whereIn('facture_lignes.produit_id', $produits->pluck('id'))
            ->groupBy('facture_lignes.produit_id')->selectRaw('facture_lignes.produit_id, MAX(factures.date_facture) AS derniere')
            ->pluck('derniere', 'produit_id');
        $limite = today()->subDays($valeurs['jours'])->toDateString();

        return $produits->map(fn (ProduitTenant $p) => [
            'numero' => $p->numero,
            'description' => $p->description,
            'stock' => round((float) ($stocks[$p->numero] ?? 0), 4),
            'unite' => $p->mesure,
            'derniere_vente' => isset($ventes[$p->id]) ? substr((string) $ventes[$p->id], 0, 10) : null,
            'jours' => isset($ventes[$p->id]) ? $this->joursDepuis($ventes[$p->id]) : null,
        ])
            ->filter(fn (array $l) => $l['stock'] > 0 && ($l['derniere_vente'] === null || $l['derniere_vente'] < $limite))
            ->sortByDesc(fn (array $l) => $l['jours'] ?? PHP_INT_MAX)->values()->all();
    }
}
