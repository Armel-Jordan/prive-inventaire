<?php

namespace App\Http\Controllers\Api;

use App\Models\Fournisseur;

/** Listes rattachées à une fiche fournisseur : au plus un élément « principal » par fournisseur. */
abstract class FournisseurFicheElementController extends FicheElementController
{
    protected function modeleParent(): string
    {
        return Fournisseur::class;
    }

    protected function cleParent(): string
    {
        return 'fournisseur_id';
    }
}
