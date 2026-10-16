<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComFourEntete;
use App\Services\BonCommandePdf;
use Illuminate\Http\Response;

/**
 * PDF du bon de commande, servi comme les documents de vente (jeton Sanctum en en-tête, entreprise
 * résolue par le middleware) : plus de jeton dans l'URL, qui finissait dans les journaux.
 */
class BonCommandePdfController extends Controller
{
    public function __invoke(int $id, BonCommandePdf $pdf): Response
    {
        $commande = ComFourEntete::findOrFail($id);

        return $pdf->pdf($commande)->stream("bon-commande-{$commande->numero}.pdf");
    }
}
