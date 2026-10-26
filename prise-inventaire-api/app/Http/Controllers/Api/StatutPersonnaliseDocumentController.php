<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StatutsPersonnalises;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pose d'un statut personnalisé sur un document (phase 1.11c) : `PUT /{documents}/{id}/statut-personnalise`, le type
 * de document venant de la route (valeur par défaut « document »). Le droit de modifier le document est contrôlé par
 * le middleware des droits ; le statut du document n'est pas regardé (un document clos s'étiquette aussi).
 */
class StatutPersonnaliseDocumentController extends Controller
{
    public function __construct(private readonly StatutsPersonnalises $statuts) {}

    public function poser(Request $request, int $id): JsonResponse
    {
        $type = (string) $request->route('document');
        $modele = StatutsPersonnalises::DOCUMENTS[$type] ?? abort(404);
        $document = $modele::query()->findOrFail($id);
        $donnees = $request->validate([
            'statut_personnalise_id' => ['present', ...StatutsPersonnalises::regle($type, $document->getAttribute('statut_personnalise_id'))],
        ]);

        return response()->json([
            'statut_personnalise_id' => $donnees['statut_personnalise_id'],
            'statut_personnalise' => $this->statuts->poser($document, $donnees['statut_personnalise_id']),
        ]);
    }
}
