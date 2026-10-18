<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ColisLivraison;
use App\Models\Tournee;
use App\Services\ChargementsTournee;
use App\Services\FicheChargementPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Chargement du camion d'une tournée : état, colis et vrac cochés, étiquette scannée, fiche de chargement (PDF). */
class ChargementTourneeController extends Controller
{
    public function __construct(private readonly ChargementsTournee $chargements) {}

    public function show(int $id): JsonResponse
    {
        return response()->json($this->chargements->etat(Tournee::findOrFail($id)));
    }

    public function colis(Request $request, int $id, int $colisId): JsonResponse
    {
        $tournee = Tournee::findOrFail($id);
        $charge = $request->validate(['charge' => ['required', 'boolean']])['charge'];
        $this->chargements->chargerColis($tournee, ColisLivraison::findOrFail($colisId), (bool) $charge, $request->user()?->id);

        return response()->json($this->chargements->etat($tournee->fresh()));
    }

    public function scanner(Request $request, int $id): JsonResponse
    {
        $tournee = Tournee::findOrFail($id);
        $numero = $request->validate(['numero' => ['required', 'string', 'max:60']])['numero'];
        [$colis, $deja] = $this->chargements->scanner($tournee, $numero, $request->user()?->id);

        return response()->json([
            'message' => $deja ? __('Colis :numero déjà chargé.', ['numero' => $colis->numero]) : __('Colis :numero chargé.', ['numero' => $colis->numero]),
            'colis_id' => $colis->id,
            'deja' => $deja,
        ] + $this->chargements->etat($tournee->fresh()));
    }

    public function vrac(Request $request, int $id, int $bonId): JsonResponse
    {
        $tournee = Tournee::findOrFail($id);
        $charge = $request->validate(['charge' => ['required', 'boolean']])['charge'];
        $this->chargements->chargerVrac($tournee, $bonId, (bool) $charge, $request->user()?->id);

        return response()->json($this->chargements->etat($tournee->fresh()));
    }

    /** Fiche de chargement (PDF) : ce qui monte dans le camion, dans l'ordre de chargement. */
    public function pdf(int $id, FicheChargementPdf $fiches): Response
    {
        $tournee = Tournee::findOrFail($id);

        return $fiches->pdf($tournee)->stream("fiche-chargement-{$tournee->numero}.pdf");
    }
}
