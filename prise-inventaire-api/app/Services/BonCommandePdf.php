<?php

namespace App\Services;

use App\Models\ComFourEntete;
use App\Support\EntreprisePdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentPdf;

/**
 * PDF du bon de commande fournisseur, au nom et dans la devise de l'entreprise qui commande :
 * le même pour le téléchargement et pour l'envoi par courriel.
 */
class BonCommandePdf
{
    public function pdf(ComFourEntete $commande): DocumentPdf
    {
        $commande->loadMissing(['fournisseur', 'lignes.produit', 'createdBy']);

        return Pdf::loadView('pdf.bon-commande', ['commande' => $commande] + EntreprisePdf::entete())
            ->setPaper('A4', 'portrait')
            ->setOption('isFontSubsettingEnabled', true);
    }
}
