<?php

namespace App\Rapports;

use App\Models\FactureLigne;
use App\Models\ProduitTenant;

/**
 * Ventes par produit : quantité vendue (en unités de stock : une caisse de 12 compte pour 12), chiffre d'affaires hors
 * taxes et prix moyen, du produit qui rapporte le plus au moins. Filtre possible par catégorie de produits.
 */
class VentesParProduit extends RapportVentes
{
    public function code(): string
    {
        return 'ventes-par-produit';
    }

    public function titre(): array
    {
        return ['fr' => 'Ventes par produit', 'en' => 'Sales by product'];
    }

    public function description(): array
    {
        return ['fr' => "Les lignes des factures émises par produit : quantité vendue en unités de stock, chiffre d'affaires hors taxes, prix moyen.",
            'en' => 'Lines of issued invoices by product: quantity sold in stock units, revenue excluding tax, average price.'];
    }

    public function parametres(): array
    {
        return $this->periode() + [
            'categorie' => ['type' => 'categorie', 'libelle' => ['fr' => 'Catégorie', 'en' => 'Category']],
            'limite' => ['type' => 'entier', 'libelle' => ['fr' => 'Nombre de produits', 'en' => 'Number of products'], 'defaut' => 100, 'min' => 1, 'max' => 5000],
        ];
    }

    public function colonnes(array $valeurs): array
    {
        return [
            'numero' => ['libelle' => ['fr' => 'Produit', 'en' => 'Product'], 'type' => 'texte'],
            'description' => ['libelle' => ['fr' => 'Description', 'en' => 'Description'], 'type' => 'texte'],
            'categorie' => ['libelle' => ['fr' => 'Catégorie', 'en' => 'Category'], 'type' => 'texte'],
            'quantite' => ['libelle' => ['fr' => 'Quantité', 'en' => 'Quantity'], 'type' => 'quantite', 'total' => true],
            'unite' => ['libelle' => ['fr' => 'Unité', 'en' => 'Unit'], 'type' => 'texte'],
            'chiffre_affaires' => ['libelle' => ['fr' => "Chiffre d'affaires HT", 'en' => 'Revenue excl. tax'], 'type' => 'montant', 'total' => true],
            'prix_moyen' => ['libelle' => ['fr' => 'Prix moyen', 'en' => 'Average price'], 'type' => 'montant'],
        ];
    }

    public function lignes(array $valeurs): array
    {
        $factures = $this->factures($valeurs)->select('id');
        $totaux = FactureLigne::query()->whereIn('facture_lignes.facture_id', $factures)
            ->join('factures', 'factures.id', '=', 'facture_lignes.facture_id')
            ->whereNotNull('facture_lignes.produit_id')
            ->when($valeurs['categorie'], fn ($q, $categorie) => $q->whereIn('facture_lignes.produit_id', ProduitTenant::withTrashed()->where('categorie', $categorie)->select('id')))
            ->groupBy('facture_lignes.produit_id')
            ->selectRaw('facture_lignes.produit_id, SUM(facture_lignes.quantite * COALESCE(facture_lignes.facteur, 1)) AS quantite,
                SUM(facture_lignes.montant_ht * factures.taux_change) AS chiffre_affaires')
            ->orderByDesc('chiffre_affaires')->limit($valeurs['limite'])->get();
        $produits = ProduitTenant::withTrashed()->whereIn('id', $totaux->pluck('produit_id'))->get(['id', 'numero', 'description', 'categorie', 'mesure'])->keyBy('id');

        return $totaux->map(function ($t) use ($produits) {
            $produit = $produits[$t->produit_id] ?? null;
            $quantite = round((float) $t->quantite, 4);

            return [
                'numero' => $produit->numero ?? '',
                'description' => $produit->description ?? '',
                'categorie' => $produit->categorie ?? '',
                'quantite' => $quantite,
                'unite' => $produit->mesure ?? '',
                'chiffre_affaires' => $this->argent($t->chiffre_affaires),
                'prix_moyen' => $quantite > 0 ? $this->argent((float) $t->chiffre_affaires / $quantite) : null,
            ];
        })->values()->all();
    }
}
