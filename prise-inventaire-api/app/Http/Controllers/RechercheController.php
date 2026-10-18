<?php

namespace App\Http\Controllers;

use App\Services\RechercheGlobale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Recherche globale : fiches et documents de l'entreprise, regroupés par type (phase 6.4). */
class RechercheController extends Controller
{
    public function __invoke(Request $request, RechercheGlobale $recherche): JsonResponse
    {
        $donnees = $request->validate([
            'q' => ['required', 'string', 'max:100'],
            // Types séparés par des virgules (ceux que l'utilisateur peut consulter) ; tous par défaut.
            'types' => ['nullable', 'string', 'max:300'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $texte = trim($donnees['q']);
        if (mb_strlen($texte) < 2) {
            return response()->json(['message' => __('Saisissez au moins 2 caractères.'), 'errors' => ['q' => [__('Saisissez au moins 2 caractères.')]]], 422);
        }
        $types = isset($donnees['types'])
            ? array_values(array_intersect(RechercheGlobale::TYPES, array_map('trim', explode(',', $donnees['types']))))
            : RechercheGlobale::TYPES;

        return response()->json([
            'q' => $texte,
            'groupes' => $recherche->rechercher($texte, $types, $donnees['limite'] ?? 5, $request->attributes->get('tenant')),
        ]);
    }
}
