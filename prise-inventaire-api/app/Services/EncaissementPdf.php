<?php

namespace App\Services;

use App\Models\Encaissement;
use App\Support\EntreprisePdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentPdf;

/**
 * Reçu d'un encaissement (plan de parité fripv2, phase 4.4) : la preuve de paiement remise au client, avec la date, le
 * mode, la référence, les factures réglées, l'escompte accordé et le surplus laissé à son crédit.
 */
class EncaissementPdf
{
    public function __construct(private readonly Encaissements $encaissements, private readonly DevisesVente $devises) {}

    public function pdf(Encaissement $encaissement): DocumentPdf
    {
        // Le client en entier : ses coordonnées s'impriment.
        $encaissement->load($this->encaissements->relations())->load('client');

        return Pdf::loadView('pdf.recu-encaissement', [
            'encaissement' => $encaissement,
            'symbole' => $this->devises->symbole($encaissement->devise),
        ] + EntreprisePdf::entete())
            ->setPaper('A4', 'portrait')
            ->setOption('isFontSubsettingEnabled', true);
    }
}
