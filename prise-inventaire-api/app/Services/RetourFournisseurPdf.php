<?php

namespace App\Services;

use App\Models\RetourFournisseur;
use App\Support\EntreprisePdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentPdf;

/**
 * PDF du bon de retour fournisseur (à joindre au colis, avec le numéro RMA), au nom de l'entreprise et dans la devise
 * du retour : le même pour le téléchargement et pour l'envoi par courriel.
 */
class RetourFournisseurPdf
{
    public function __construct(private readonly DevisesVente $devises) {}

    public function pdf(RetourFournisseur $retour): DocumentPdf
    {
        $retour->loadMissing([
            'fournisseur', 'commande:id,numero', 'retourClient:id,numero', 'auteur:id,nom',
            'lignes.produit' => fn ($q) => $q->withTrashed(), 'lignes.raison',
        ]);
        $entete = EntreprisePdf::entete();
        // Retour dans une devise étrangère : montants suivis de son code, et ligne d'information avec le taux.
        $symbole = $retour->devise ? $retour->devise : $entete['devise'];

        return Pdf::loadView('pdf.retour-fournisseur', [
            'retour' => $retour,
            'symbole' => $symbole,
            'reference' => $this->devises->reference(),
        ] + $entete)
            ->setPaper('A4', 'portrait')
            ->setOption('isFontSubsettingEnabled', true);
    }
}
