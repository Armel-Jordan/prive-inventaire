<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComFourEntete;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class BonCommandePdfController extends Controller
{
    public function generate(ComFourEntete $commande): Response
    {
        $commande->load(['fournisseur', 'lignes.produit', 'createdBy']);

        $pdf = Pdf::loadView('pdf.bon-commande', [
            'commande' => $commande,
        ]);

        $pdf->setPaper('A4', 'portrait');

        return $pdf->download("bon-commande-{$commande->numero}.pdf");
    }

    public function preview(ComFourEntete $commande): Response
    {
        $commande->load(['fournisseur', 'lignes.produit', 'createdBy']);

        $pdf = Pdf::loadView('pdf.bon-commande', [
            'commande' => $commande,
        ]);

        $pdf->setPaper('A4', 'portrait');

        return $pdf->stream("bon-commande-{$commande->numero}.pdf");
    }
}
