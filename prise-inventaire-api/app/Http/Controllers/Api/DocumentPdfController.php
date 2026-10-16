<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Avoir;
use App\Models\BonLivraison;
use App\Models\ComClientEntete;
use App\Models\Devis;
use App\Models\Facture;
use App\Services\DocumentsVentePdf;
use Illuminate\Http\Response;

/**
 * PDF des documents de vente, servis comme le reste de l'API (jeton Sanctum en en-tête,
 * tenant résolu par le middleware) : pas de jeton dans l'URL.
 */
class DocumentPdfController extends Controller
{
    public function __construct(private readonly DocumentsVentePdf $documents) {}

    public function devis(Devis $devis): Response
    {
        return $this->documents->pdf($this->documents->devis($devis))->stream("devis-{$devis->numero}.pdf");
    }

    public function commande(int $id): Response
    {
        $commande = ComClientEntete::findOrFail($id);

        return $this->documents->pdf($this->documents->commande($commande))->stream("commande-{$commande->numero}.pdf");
    }

    public function facture(int $id): Response
    {
        $facture = Facture::findOrFail($id);

        return $this->documents->pdf($this->documents->facture($facture))->stream("facture-{$facture->numero}.pdf");
    }

    public function avoir(int $id): Response
    {
        $avoir = Avoir::findOrFail($id);
        // Un brouillon n'a pas encore de numéro.
        $nom = $avoir->numero ?? 'brouillon-'.$avoir->id;

        return $this->documents->pdf($this->documents->avoir($avoir))->stream("note-credit-{$nom}.pdf");
    }

    public function bonLivraison(int $id): Response
    {
        $bon = BonLivraison::findOrFail($id);

        return $this->documents->pdf($this->documents->bonLivraison($bon))->stream("bon-livraison-{$bon->numero}.pdf");
    }
}
