<?php

namespace App\Services;

use App\Models\ProduitTenant;
use Illuminate\Support\Facades\DB;

/**
 * Unités d'achat (plan de parité fripv2, phase 2.2, suite) :
 * - un fournisseur livre un produit dans son conditionnement (la caisse de 24) : celui de son catalogue, sinon celui du
 *   produit (unité d'achat et unités de stock par unité d'achat) ;
 * - une ligne de commande fournisseur le fige : ses quantités et son prix sont en unités d'achat ;
 * - ce qui se compte en stock (en commande, réceptions réparties sur les reliquats, retours au fournisseur) se convertit
 *   avec son facteur ; un besoin en unités de stock se commande en unités d'achat entières, arrondies au-dessus.
 */
class UnitesAchat
{
    /**
     * Conditionnement d'un produit chez un fournisseur : celui de son catalogue s'il en a un, sinon celui du produit.
     *
     * @return array{unite_achat: ?string, qte_par_unite_achat: int}
     */
    public function pour(?ProduitTenant $produit, ?int $fournisseurId): array
    {
        $catalogue = $produit && $fournisseurId
            ? DB::table('produit_fournisseur')->where('produit_id', $produit->id)->where('fournisseur_id', $fournisseurId)->first(['unite_achat', 'qte_par_unite_achat'])
            : null;
        if ($catalogue && ($catalogue->unite_achat !== null || $catalogue->qte_par_unite_achat !== null)) {
            return [
                'unite_achat' => $catalogue->unite_achat ?? $produit?->unite_achat,
                'qte_par_unite_achat' => max(1, (int) ($catalogue->qte_par_unite_achat ?? $produit?->qte_par_unite_achat ?? 1)),
            ];
        }

        return ['unite_achat' => $produit?->unite_achat, 'qte_par_unite_achat' => max(1, (int) ($produit?->qte_par_unite_achat ?? 1))];
    }

    /** Unités d'achat entières qui couvrent une quantité en unités de stock (24 unités en caisses de 12 : 2 ; 25 : 3). */
    public static function enAchat(float $quantiteStock, int $facteur): int
    {
        return (int) ceil(round($quantiteStock / max(1, $facteur), 6));
    }
}
