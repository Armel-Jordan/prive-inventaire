<?php

namespace App\Services;

use App\Models\CarteCadeau;
use App\Support\EntreprisePdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentPdf;

/** Certificat cadeau : l'entreprise, le numéro de la carte, son montant, son solde du moment et son expiration. */
class CertificatCadeauPdf
{
    public function __construct(private readonly DevisesVente $devises) {}

    public function certificat(CarteCadeau $carte): DocumentPdf
    {
        $entete = EntreprisePdf::entete();

        return Pdf::loadView('pdf.certificat-cadeau', [
            'entreprise' => $entete['entreprise'],
            'symbole' => $carte->devise ? $this->devises->symbole($carte->devise) : $entete['devise'],
            'carte' => $carte,
        ])->setPaper('A5', 'landscape')->setOption('isFontSubsettingEnabled', true);
    }
}
