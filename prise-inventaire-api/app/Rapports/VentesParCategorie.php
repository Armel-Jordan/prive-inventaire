<?php

namespace App\Rapports;

use App\Models\FactureLigne;
use App\Models\ProduitTenant;

/** Ventes par catégorie de produits : quantité, chiffre d'affaires hors taxes et part du total. */
class VentesParCategorie extends RapportVentes
{
    public function code(): string
    {
        return 'ventes-par-categorie';
    }

    public function titre(): array
    {
        return ['fr' => 'Ventes par catégorie', 'en' => 'Sales by category'];
    }

    public function description(): array
    {
        return ['fr' => "Le chiffre d'affaires hors taxes des factures émises par catégorie de produits, et la part de chacune.",
            'en' => 'Revenue excluding tax of issued invoices by product category, and the share of each.'];
    }

    public function parametres(): array
    {
        return $this->periode();
    }

    public function colonnes(array $valeurs): array
    {
        return [
            'categorie' => ['libelle' => ['fr' => 'Catégorie', 'en' => 'Category'], 'type' => 'texte'],
            'produits' => ['libelle' => ['fr' => 'Produits vendus', 'en' => 'Products sold'], 'type' => 'entier'],
            'quantite' => ['libelle' => ['fr' => 'Quantité', 'en' => 'Quantity'], 'type' => 'quantite', 'total' => true],
            'chiffre_affaires' => ['libelle' => ['fr' => "Chiffre d'affaires HT", 'en' => 'Revenue excl. tax'], 'type' => 'montant', 'total' => true],
            'part' => ['libelle' => ['fr' => 'Part', 'en' => 'Share'], 'type' => 'pourcentage', 'total' => true],
        ];
    }

    public function lignes(array $valeurs): array
    {
        $lignes = FactureLigne::query()->whereIn('facture_lignes.facture_id', $this->factures($valeurs)->select('id'))
            ->join('factures', 'factures.id', '=', 'facture_lignes.facture_id')
            ->whereNotNull('facture_lignes.produit_id')
            ->groupBy('facture_lignes.produit_id')
            ->selectRaw('facture_lignes.produit_id, SUM(facture_lignes.quantite * COALESCE(facture_lignes.facteur, 1)) AS quantite,
                SUM(facture_lignes.montant_ht * factures.taux_change) AS chiffre_affaires')
            ->get();
        $categories = ProduitTenant::withTrashed()->whereIn('id', $lignes->pluck('produit_id'))->pluck('categorie', 'id');
        $total = (float) $lignes->sum('chiffre_affaires');

        return $lignes->groupBy(fn ($l) => ($categories[$l->produit_id] ?? null) ?: '—')
            ->map(fn ($groupe, $categorie) => [
                'categorie' => (string) $categorie,
                'produits' => $groupe->count(),
                'quantite' => round((float) $groupe->sum('quantite'), 4),
                'chiffre_affaires' => $this->argent($groupe->sum('chiffre_affaires')),
                'part' => $total > 0 ? round(100 * (float) $groupe->sum('chiffre_affaires') / $total, 1) : 0.0,
            ])
            ->sortByDesc('chiffre_affaires')->values()->all();
    }
}
