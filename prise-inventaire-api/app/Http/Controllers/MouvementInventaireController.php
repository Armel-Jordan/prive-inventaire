<?php

namespace App\Http\Controllers;

use App\Models\MouvementInventaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MouvementInventaireController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MouvementInventaire::query();

        if ($request->has('scan_id')) {
            $query->where('scan_id', $request->scan_id);
        }

        if ($request->has('type_mouvement')) {
            $query->where('type_mouvement', $request->type_mouvement);
        }

        if ($request->has('date_debut')) {
            $query->where('date_mouvement', '>=', $request->date_debut);
        }

        if ($request->has('date_fin')) {
            $query->where('date_mouvement', '<=', $request->date_fin);
        }

        $mouvements = $query->orderByDesc('date_mouvement')
            ->limit(100)
            ->get();

        return response()->json($mouvements);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'scan_id' => 'required|integer',
            'type_mouvement' => 'required|in:ENTREE,SORTIE,CORRECTION',
            'quantite_avant' => 'required|numeric|min:0',
            'quantite_apres' => 'required|numeric|min:0',
            'motif' => 'nullable|string|max:255',
            'utilisateur' => 'required|string|max:50',
        ]);

        $mouvement = MouvementInventaire::create([
            'scan_id' => $request->scan_id,
            'type_mouvement' => $request->type_mouvement,
            'quantite_avant' => $request->quantite_avant,
            'quantite_apres' => $request->quantite_apres,
            'motif' => $request->motif,
            'utilisateur' => $request->utilisateur,
            'date_mouvement' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Mouvement enregistré avec succès',
            'mouvement' => $mouvement,
        ], 201);
    }

    public function show($id): JsonResponse
    {
        $mouvement = MouvementInventaire::findOrFail($id);

        return response()->json($mouvement);
    }

    public function getByScan($scanId): JsonResponse
    {
        $mouvements = MouvementInventaire::where('scan_id', $scanId)
            ->orderByDesc('date_mouvement')
            ->get();

        return response()->json($mouvements);
    }
}
