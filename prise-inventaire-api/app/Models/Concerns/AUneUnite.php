<?php

namespace App\Models\Concerns;

/**
 * Ligne de document dans une unité de vente du produit (colonnes unite et facteur, voir App\Services\UnitesProduits) :
 * sans unité, la ligne est dans l'unité de stock du produit, au facteur 1.
 */
trait AUneUnite
{
    /** Unités de stock dans une unité de la ligne. */
    public function facteurStock(): float
    {
        $facteur = $this->getAttribute('facteur');

        return $facteur === null ? 1.0 : (float) $facteur;
    }
}
