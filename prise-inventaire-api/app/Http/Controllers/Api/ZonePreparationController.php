<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ZonePreparation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZonePreparationController extends Controller
{
    public function index(): JsonResponse
    {
        $zones = ZonePreparation::orderBy('code')->get();
        return response()->json($zones);
    }

    public function show(int $id): JsonResponse
    {
        $zone = ZonePreparation::with('produitLocalisations')->findOrFail($id);
        return response()->json($zone);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:20|unique:zones_preparation',
            'nom' => 'required|string|max:100',
            'description' => 'nullable|string',
        ]);

        $validated['actif'] = true;
        $zone = ZonePreparation::create($validated);

        return response()->json($zone, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $zone = ZonePreparation::findOrFail($id);

        $validated = $request->validate([
            'code' => 'sometimes|string|max:20|unique:zones_preparation,code,' . $id,
            'nom' => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'actif' => 'sometimes|boolean',
        ]);

        $zone->update($validated);
        return response()->json($zone);
    }

    public function destroy(int $id): JsonResponse
    {
        $zone = ZonePreparation::findOrFail($id);
        $zone->delete();
        return response()->json(['message' => 'Zone supprimée']);
    }
}
