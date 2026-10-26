<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BonLivraison;
use App\Models\ComClientEntete;
use App\Models\Devis;
use App\Models\Facture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Document prioritaire (plan de parité fripv2, socle S4.1, l'étoile) : `PUT /{documents}/{id}/prioritaire`, le type de
 * document venant de la route (valeur par défaut « document »). Le droit de modifier le document est contrôlé par le
 * middleware des droits ; le statut du document n'est pas regardé (un document clos se marque aussi).
 */
class PrioriteDocumentController extends Controller
{
    /** Type de document => modèle. */
    public const DOCUMENTS = [
        'devis' => Devis::class,
        'commande_client' => ComClientEntete::class,
        'facture' => Facture::class,
        'bon_livraison' => BonLivraison::class,
    ];

    public function poser(Request $request, int $id): JsonResponse
    {
        $modele = self::DOCUMENTS[(string) $request->route('document')] ?? abort(404);
        $document = $modele::query()->findOrFail($id);
        $prioritaire = $request->validate(['prioritaire' => ['required', 'boolean']])['prioritaire'];
        $document->forceFill(['prioritaire' => (bool) $prioritaire])->save();

        return response()->json(['prioritaire' => (bool) $document->prioritaire]);
    }
}
