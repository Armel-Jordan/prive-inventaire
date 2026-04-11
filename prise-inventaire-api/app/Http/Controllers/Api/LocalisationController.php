<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MouvementVente;
use App\Models\ProduitLocalisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocalisationController extends Controller
{
    public function produits(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $query = ProduitLocalisation::where('tenant_id', $tenantId)->where('quantite', '>', 0);

        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }

        $localisations = $query->get()->groupBy('produit_id');

        return response()->json($localisations);
    }

    public function parSecteur(int $secteurId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $localisations = ProduitLocalisation::where('tenant_id', $tenantId)
            ->where('localisation_type', 'secteur')
            ->where('localisation_id', $secteurId)
            ->where('quantite', '>', 0)
            ->get();

        return response()->json($localisations);
    }

    public function parCamion(int $camionId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $localisations = ProduitLocalisation::where('tenant_id', $tenantId)
            ->where('localisation_type', 'camion')
            ->where('localisation_id', $camionId)
            ->where('quantite', '>', 0)
            ->get();

        return response()->json($localisations);
    }

    public function parZonePreparation(int $zoneId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $localisations = ProduitLocalisation::where('tenant_id', $tenantId)
            ->where('localisation_type', 'zone_preparation')
            ->where('localisation_id', $zoneId)
            ->where('quantite', '>', 0)
            ->get();

        return response()->json($localisations);
    }

    public function produitLocalisation(int $produitId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $localisations = ProduitLocalisation::where('tenant_id', $tenantId)
            ->where('produit_id', $produitId)
            ->where('quantite', '>', 0)
            ->get();

        $stockDisponible = $localisations->where('statut', 'disponible')->sum('quantite');
        $stockReserve = $localisations->where('statut', 'reserve')->sum('quantite');
        $stockEnPreparation = $localisations->where('statut', 'en_preparation')->sum('quantite');
        $stockEnTransit = $localisations->where('statut', 'en_transit')->sum('quantite');

        return response()->json([
            'produit_id' => $produitId,
            'localisations' => $localisations,
            'resume' => [
                'disponible' => $stockDisponible,
                'reserve' => $stockReserve,
                'en_preparation' => $stockEnPreparation,
                'en_transit' => $stockEnTransit,
                'total' => $localisations->sum('quantite'),
            ],
        ]);
    }

    public function mouvements(Request $request): JsonResponse
    {
        $query = MouvementVente::query();

        if ($request->has('produit_id')) {
            $query->where('produit_id', $request->produit_id);
        }
        if ($request->has('type_mouvement')) {
            $query->where('type_mouvement', $request->type_mouvement);
        }
        if ($request->has('date_debut')) {
            $query->whereDate('created_at', '>=', $request->date_debut);
        }
        if ($request->has('date_fin')) {
            $query->whereDate('created_at', '<=', $request->date_fin);
        }

        $mouvements = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 50));

        return response()->json($mouvements);
    }

    public function mouvementsProduit(int $produitId): JsonResponse
    {
        $mouvements = MouvementVente::where('produit_id', $produitId)
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return response()->json($mouvements);
    }
}
