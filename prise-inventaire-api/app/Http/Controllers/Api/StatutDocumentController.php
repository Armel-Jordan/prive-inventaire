<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StatutDocumentReglage;
use App\Support\StatutsDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Statuts des documents : libellés et couleurs des statuts système, lus par tous les écrans (lecture libre) et réglés
 * avec le droit de modifier la configuration.
 */
class StatutDocumentController extends Controller
{
    public function __construct(private readonly StatutsDocuments $statuts) {}

    public function index(): JsonResponse
    {
        return response()->json($this->statuts->tous());
    }

    public function update(Request $request, string $document, string $code): JsonResponse
    {
        abort_unless(StatutsDocuments::existe($document, $code), 422, __("Ce statut de document n'existe pas."));
        $donnees = $request->validate([
            'libelle_fr' => ['nullable', 'string', 'max:60'],
            'libelle_en' => ['nullable', 'string', 'max:60'],
            'couleur_fond' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'couleur_texte' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        StatutDocumentReglage::query()->updateOrCreate(['document' => $document, 'code' => $code], $donnees);
        $this->statuts->oublier();

        return response()->json($this->statuts->tous()[$document]);
    }

    /** Retour aux libellés et couleurs par défaut. */
    public function destroy(string $document, string $code): JsonResponse
    {
        abort_unless(StatutsDocuments::existe($document, $code), 422, __("Ce statut de document n'existe pas."));
        StatutDocumentReglage::query()->where('document', $document)->where('code', $code)->delete();
        $this->statuts->oublier();

        return response()->json($this->statuts->tous()[$document]);
    }
}
