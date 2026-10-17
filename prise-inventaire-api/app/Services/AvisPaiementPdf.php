<?php

namespace App\Services;

use App\Models\Debourse;
use App\Support\EntreprisePdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentPdf;

/**
 * Avis de paiement d'un déboursé (plan de parité fripv2, phase 4.5) : ce que l'entreprise verse au fournisseur, avec
 * la date, le mode, la référence et les factures réglées (son numéro et le nôtre, escompte obtenu), à lui transmettre
 * avec le chèque ou le virement.
 */
class AvisPaiementPdf
{
    public function __construct(private readonly Debourses $debourses, private readonly DevisesVente $devises) {}

    public function pdf(Debourse $debourse): DocumentPdf
    {
        // Le fournisseur en entier : ses coordonnées s'impriment.
        $debourse->load($this->debourses->relations())->load('fournisseur');

        return Pdf::loadView('pdf.avis-paiement', [
            'debourse' => $debourse,
            'symbole' => $this->devises->symbole($debourse->devise),
        ] + EntreprisePdf::entete())
            ->setPaper('A4', 'portrait')
            ->setOption('isFontSubsettingEnabled', true);
    }
}
