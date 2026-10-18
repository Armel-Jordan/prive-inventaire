<?php

namespace App\Http\Controllers;

use App\Models\Verrou;
use App\Services\Verrous;
use App\Support\Droits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Verrous des fiches en cours de modification (plan de parité fripv2, phase 6.3) : l'écran pose le verrou à l'ouverture
 * de la modification, le renouvelle chaque minute et le lève à la fermeture ; il faut le droit de modifier la fiche.
 * Prendre la main sur la fiche d'un autre compte, ou la libérer depuis la liste, demande le droit de libérer.
 */
class VerrouController extends Controller
{
    /** Fiches en cours de modification (droit de les voir, contrôlé par les droits de l'API). */
    public function index(Request $request, Verrous $verrous): JsonResponse
    {
        return response()->json($verrous->enCours($request->user()));
    }

    public function poser(Request $request, Verrous $verrous, Droits $droits): JsonResponse
    {
        $donnees = $request->validate($this->regles() + [
            'prendre_la_main' => ['sometimes', 'boolean'],
            'renouveler' => ['sometimes', 'boolean'],
        ]);
        $compte = $request->user();
        $entite = $donnees['entite'];
        $id = (int) $donnees['entite_id'];
        abort_unless($droits->permet($compte, Verrous::module($entite), 'modifier'), 403, __("Vous n'avez pas le droit de modifier cette fiche."));
        $prendreLaMain = (bool) ($donnees['prendre_la_main'] ?? false);
        abort_if($prendreLaMain && ! $droits->permet($compte, 'verrous', 'liberer'), 403, $droits->messageRefus('verrous', 'liberer'));
        abort_unless($verrous->existe($entite, $id), 404, __('Fiche introuvable.'));

        [$verrou, $empechement] = $verrous->poser($entite, $id, $compte, $prendreLaMain, (bool) ($donnees['renouveler'] ?? false));
        if ($empechement) {
            return response()->json(['message' => Verrous::message($empechement), 'verrou' => Verrous::presenter($empechement)], 409);
        }

        return response()->json(['verrou' => Verrous::presenter($verrou)]);
    }

    public function lever(Request $request, Verrous $verrous): JsonResponse
    {
        $donnees = $request->validate($this->regles());
        $verrous->lever($donnees['entite'], (int) $donnees['entite_id'], $request->user());

        return response()->json(null, 204);
    }

    /** Libère la fiche d'un autre compte (droit de libérer, contrôlé par les droits de l'API). */
    public function liberer(Request $request, Verrous $verrous, int $id): JsonResponse
    {
        $verrou = Verrou::query()->findOrFail($id);
        abort_unless($verrou->actif(), 404, __("Cette fiche n'est plus en cours de modification."));
        $verrous->liberer($verrou, $request->user());

        return response()->json(null, 204);
    }

    private function regles(): array
    {
        return [
            'entite' => ['required', Rule::in(array_keys(Verrous::ENTITES))],
            'entite_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
