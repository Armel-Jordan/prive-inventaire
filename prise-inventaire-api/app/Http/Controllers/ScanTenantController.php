<?php

namespace App\Http\Controllers;

use App\Models\ScanTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScanTenantController extends Controller
{
    public function historique(Request $request): JsonResponse
    {
        $query = ScanTenant::query()->whereNull('deleted_at');

        if ($request->has('employe') && $request->employe) {
            $query->where('employe', $request->employe);
        }

        if ($request->has('secteur') && $request->secteur) {
            $query->where('secteur', $request->secteur);
        }

        $scans = $query->orderByDesc('date_saisie')
            ->limit(100)
            ->get();

        return response()->json($scans);
    }

    public function enregistrer(Request $request): JsonResponse
    {
        $request->validate([
            'numero' => 'required|string|max:50',
            'quantite' => 'required|numeric|min:0',
            'employe' => 'required|string|max:50',
            'secteur' => 'required|string|max:100',
            'type' => 'nullable|string|max:50',
            'unite_mesure' => 'sometimes|string|max:20',
            'scanneur' => 'nullable|string|max:50',
        ]);

        $scan = ScanTenant::create([
            'numero' => $request->numero,
            'type' => $request->type,
            'quantite' => $request->quantite,
            'unite_mesure' => $request->unite_mesure ?? 'UN',
            'employe' => $request->employe,
            'secteur' => $request->secteur,
            'date_saisie' => now(),
            'scanneur' => $request->scanneur,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Scan enregistré avec succès',
            'scan' => $scan,
        ], 201);
    }

    public function modifier(Request $request, $id): JsonResponse
    {
        $scan = ScanTenant::findOrFail($id);

        $request->validate([
            'quantite' => 'required|numeric|min:0',
        ]);

        $scan->quantite = $request->quantite;
        $scan->save();

        return response()->json([
            'success' => true,
            'message' => 'Scan modifié avec succès',
            'scan' => $scan,
        ]);
    }

    public function supprimer($id): JsonResponse
    {
        $scan = ScanTenant::findOrFail($id);
        $scan->deleted_at = now();
        $scan->save();

        return response()->json([
            'success' => true,
            'message' => 'Scan supprimé avec succès',
        ]);
    }

    public function stats(): JsonResponse
    {
        $total = ScanTenant::whereNull('deleted_at')->count();
        $today = ScanTenant::whereNull('deleted_at')
            ->whereDate('date_saisie', today())
            ->count();

        return response()->json([
            'total' => $total,
            'today' => $today,
        ]);
    }
}
