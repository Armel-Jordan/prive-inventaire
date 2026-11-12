<?php

namespace App\Support;

use App\Models\ProduitTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Poids estimé d'un document de vente (plan de parité fripv2, socle S4.1, « poids approximatif ») : quantité × facteur de
 * l'unité × poids du produit, en kilogrammes, sur les lignes dont le produit a un poids. Null si aucun n'en a.
 */
class PoidsDocuments
{
    /**
     * @param  iterable<Model>  $lignes  lignes du document (avec produit_id et l'unité, voir AUneUnite)
     * @param  string  $quantite  colonne de la quantité (quantite_a_livrer pour un bon de livraison)
     */
    public static function estime(iterable $lignes, string $quantite = 'quantite'): ?float
    {
        $lignes = collect($lignes);
        $poids = ProduitTenant::withTrashed()->whereIn('id', $lignes->pluck('produit_id')->filter()->unique()->values())
            ->whereNotNull('poids_kg')->pluck('poids_kg', 'id');
        if ($poids->isEmpty()) {
            return null;
        }

        return round((float) $lignes->sum(fn (Model $ligne) => isset($poids[$ligne->getAttribute('produit_id')])
            ? (float) $ligne->getAttribute($quantite) * $ligne->facteurStock() * (float) $poids[$ligne->getAttribute('produit_id')]
            : 0), 2);
    }

    /** Poids lisible sur un document imprimé : « 1 234,5 kg ». */
    public static function lisible(?float $poids): ?string
    {
        return $poids === null ? null : rtrim(rtrim(number_format($poids, 2, ',', ' '), '0'), ',').' kg';
    }
}
