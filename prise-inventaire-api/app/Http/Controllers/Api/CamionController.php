<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Camion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CamionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Camion::query();

        if ($request->has('actif')) {
            $query->where('actif', $request->boolean('actif'));
        }
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $camions = $query->orderBy('immatriculation')->get();
        return response()->json($camions);
    }

    public function disponibles(Request $request): JsonResponse
    {
        $date = $request->get('date', now()->toDateString());

        $camions = Camion::where('actif', true)
            ->get()
            ->filter(fn($c) => $c->estDisponible($date));

        return response()->json($camions->values());
    }

    public function show(int $id): JsonResponse
    {
        $camion = Camion::with('tournees')->findOrFail($id);
        return response()->json($camion);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'immatriculation' => 'required|string|max:20|unique:camions',
            'marque' => 'nullable|string|max:50',
            'modele' => 'nullable|string|max:50',
            'type' => 'required|in:camionnette,camion,semi_remorque',
            'capacite_kg' => 'nullable|integer|min:0',
            'capacite_m3' => 'nullable|numeric|min:0',
            'date_controle_technique' => 'nullable|date',
        ]);

        $validated['actif'] = true;
        $camion = Camion::create($validated);

        return response()->json($camion, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $camion = Camion::findOrFail($id);

        $validated = $request->validate([
            'immatriculation' => 'sometimes|string|max:20|unique:camions,immatriculation,' . $id,
            'marque' => 'nullable|string|max:50',
            'modele' => 'nullable|string|max:50',
            'type' => 'sometimes|in:camionnette,camion,semi_remorque',
            'capacite_kg' => 'nullable|integer|min:0',
            'capacite_m3' => 'nullable|numeric|min:0',
            'date_controle_technique' => 'nullable|date',
            'actif' => 'sometimes|boolean',
        ]);

        $camion->update($validated);
        return response()->json($camion);
    }

    public function destroy(int $id): JsonResponse
    {
        $camion = Camion::findOrFail($id);

        if ($camion->tournees()->whereIn('statut', ['planifiee', 'en_cours'])->exists()) {
            return response()->json(['message' => 'Impossible de supprimer un camion avec des tournées actives'], 422);
        }

        $camion->delete();
        return response()->json(['message' => 'Camion supprimé']);
    }
}
