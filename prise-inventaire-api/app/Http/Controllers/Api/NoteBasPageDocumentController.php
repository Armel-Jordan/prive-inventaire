<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NotesBasPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Note de bas de page d'un document (plan de parité fripv2, socle S4.4) : `PUT /{documents}/{id}/note-bas-page`, le type
 * de document venant de la route (valeur par défaut « document ») ; `note_bas_page_id` vide pour celle par défaut, 0 pour
 * aucune. Le droit de modifier le document est contrôlé par le middleware des droits ; un document envoyé ou émis a sa
 * note figée.
 */
class NoteBasPageDocumentController extends Controller
{
    public function poser(Request $request, NotesBasPage $notes, int $id): JsonResponse
    {
        $type = (string) $request->route('document');
        $modele = array_search($type, NotesBasPage::DOCUMENTS, true) ?: abort(404);
        $document = $modele::query()->findOrFail($id);
        if (is_array($document->getAttribute('identite_figee'))) {
            return response()->json(['message' => __('La note de ce document est figée depuis son envoi ou son émission.')], 422);
        }
        $choix = $request->validate(['note_bas_page_id' => ['present', ...NotesBasPage::regle($type, $document->getAttribute('note_bas_page_id'))]])['note_bas_page_id'];
        $document->forceFill(['note_bas_page_id' => $choix === null ? null : (int) $choix])->save();

        return response()->json(['note_bas_page_id' => $document->getAttribute('note_bas_page_id'), 'texte' => $notes->texte($document)]);
    }
}
