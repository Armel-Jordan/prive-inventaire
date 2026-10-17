<?php

namespace App\Services;

use App\Models\Reception;
use App\Support\EntreprisePdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentPdf;

/**
 * Bon de réception (plan de parité fripv2, phase 3.3) : ce qui est arrivé d'un fournisseur en une livraison, ligne par
 * ligne (commande, secteur, lot, quantité, coût), avec son bon de livraison et son connaissement, à classer avec les
 * documents du fournisseur.
 */
class ReceptionPdf
{
    public function __construct(private readonly ReceptionsFournisseur $receptions) {}

    public function pdf(Reception $reception): DocumentPdf
    {
        $reception->loadMissing($this->receptions->relations());

        return Pdf::loadView('pdf.bon-reception', ['reception' => $reception] + EntreprisePdf::entete())
            ->setPaper('A4', 'portrait')
            ->setOption('isFontSubsettingEnabled', true);
    }
}
