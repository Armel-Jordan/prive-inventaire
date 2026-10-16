<?php

namespace App\Http\Controllers\Api;

use App\Models\Fournisseur;
use App\Models\FournisseurRemarque;

/** Remarques datées d'un fournisseur. Seuls leur auteur et les administrateurs les modifient ou les suppriment. */
class FournisseurFicheRemarqueController extends FicheRemarqueController
{
    protected function modeleParent(): string
    {
        return Fournisseur::class;
    }

    protected function cleParent(): string
    {
        return 'fournisseur_id';
    }

    protected function modele(): string
    {
        return FournisseurRemarque::class;
    }
}
