<?php

namespace App\Rapports;

use App\Models\ProduitTenant;
use App\Models\ScanTenant;

/** Stock des produits actifs (somme des scans, comme les disponibilités), avec le seuil d'alerte et le maximum. */
class StockProduits extends Rapport
{
    public function code(): string
    {
        return 'stock-produits';
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
        return ['fr' => 'Stock des produits', 'en' => 'Product stock'];
    }

    public function description(): array
    {
        return ['fr' => "Le stock de chaque produit actif (la somme de ses scans), son seuil d'alerte et son maximum ; « sous le seuil » ne garde que les produits à réapprovisionner.",
            'en' => 'Stock of each active product (the sum of its scans), its alert threshold and maximum; "below threshold" keeps only the products to restock.'];
    }

    public function parametres(): array
    {
        return [
            'categorie' => ['type' => 'categorie', 'libelle' => ['fr' => 'Catégorie', 'en' => 'Category']],
            'etat' => ['type' => 'choix', 'libelle' => ['fr' => 'Produits', 'en' => 'Products'], 'defaut' => 'tous', 'options' => [
                'tous' => ['fr' => 'Tous', 'en' => 'All'], 'sous_seuil' => ['fr' => 'Sous le seuil', 'en' => 'Below threshold'],
                'en_stock' => ['fr' => 'En stock', 'en' => 'In stock'], 'sans_stock' => ['fr' => 'Sans stock', 'en' => 'Out of stock'],
            ]],
        ];
    }

    public function colonnes(array $valeurs): array
    {
        return [
            'numero' => ['libelle' => ['fr' => 'Produit', 'en' => 'Product'], 'type' => 'texte'],
            'description' => ['libelle' => ['fr' => 'Description', 'en' => 'Description'], 'type' => 'texte'],
            'categorie' => ['libelle' => ['fr' => 'Catégorie', 'en' => 'Category'], 'type' => 'texte'],
            'stock' => ['libelle' => ['fr' => 'Stock', 'en' => 'Stock'], 'type' => 'quantite', 'total' => true],
            'unite' => ['libelle' => ['fr' => 'Unité', 'en' => 'Unit'], 'type' => 'texte'],
            'seuil' => ['libelle' => ['fr' => "Seuil d'alerte", 'en' => 'Alert threshold'], 'type' => 'quantite'],
            'maximum' => ['libelle' => ['fr' => 'Maximum', 'en' => 'Maximum'], 'type' => 'quantite'],
            'etat' => ['libelle' => ['fr' => 'État', 'en' => 'Status'], 'type' => 'texte'],
        ];
    }

    public function lignes(array $valeurs): array
    {
        $produits = ProduitTenant::query()->where('actif', true)
            ->when($valeurs['categorie'], fn ($q, $categorie) => $q->where('categorie', $categorie))
            ->orderBy('numero')->get(['id', 'numero', 'description', 'categorie', 'mesure', 'seuil_alerte', 'stock_maximum']);
        $stocks = ScanTenant::query()->whereIn('numero', $produits->pluck('numero'))->groupBy('numero')
            ->selectRaw('numero, SUM(quantite) AS quantite')->pluck('quantite', 'numero');
        $libelles = ['sous_seuil' => ['fr' => 'Sous le seuil', 'en' => 'Below threshold'], 'sans_stock' => ['fr' => 'Sans stock', 'en' => 'Out of stock'], 'ok' => ['fr' => '', 'en' => '']];

        return $produits->map(function (ProduitTenant $p) use ($stocks, $libelles) {
            $stock = round((float) ($stocks[$p->numero] ?? 0), 4);
            $seuil = $p->seuil_alerte !== null ? (float) $p->seuil_alerte : null;
            $etat = $stock <= 0 ? 'sans_stock' : ($seuil !== null && $seuil > 0 && $stock < $seuil ? 'sous_seuil' : 'ok');

            return [
                'numero' => $p->numero, 'description' => $p->description, 'categorie' => $p->categorie, 'stock' => $stock, 'unite' => $p->mesure,
                'seuil' => $seuil, 'maximum' => $p->stock_maximum !== null ? (float) $p->stock_maximum : null,
                'etat' => $this->texte($libelles[$etat]), '_etat' => $etat,
            ];
        })
            ->filter(fn (array $l) => match ($valeurs['etat']) {
                'sous_seuil' => $l['_etat'] === 'sous_seuil' || ($l['_etat'] === 'sans_stock' && $l['seuil'] !== null && $l['seuil'] > 0),
                'en_stock' => $l['stock'] > 0,
                'sans_stock' => $l['stock'] <= 0,
                default => true,
            })
            ->map(fn (array $l) => array_diff_key($l, ['_etat' => true]))
            ->values()->all();
    }
}
