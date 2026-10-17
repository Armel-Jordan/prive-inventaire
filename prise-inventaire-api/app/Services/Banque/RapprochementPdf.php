<?php

namespace App\Services\Banque;

use App\Models\RapprochementBancaire;
use App\Support\EntreprisePdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentPdf;

/**
 * Rapport d'un rapprochement bancaire (plan de parité fripv2, phase 4.6 : « Rapport de conciliation ») : le relevé, les
 * mouvements pointés (entrées, sorties), ceux en circulation à la date du relevé, et la preuve : solde du relevé + entrées
 * en circulation − sorties en circulation = solde comptable.
 */
class RapprochementPdf
{
    public function __construct(private readonly Rapprochements $rapprochements) {}

    public function pdf(RapprochementBancaire $rapprochement): DocumentPdf
    {
        $detail = $this->rapprochements->detail($rapprochement);

        return Pdf::loadView('pdf.rapprochement-bancaire', $detail + EntreprisePdf::entete())
            ->setPaper('A4', 'portrait')
            ->setOption('isFontSubsettingEnabled', true);
    }
}
