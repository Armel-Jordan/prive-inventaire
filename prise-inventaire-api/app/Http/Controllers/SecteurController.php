<?php

namespace App\Http\Controllers;

use App\Models\Secteur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecteurController extends Controller
{
    public function index(): JsonResponse
    {
        $secteurs = Secteur::where('actif', true)
            ->orderBy('code')
            ->get();

        return response()->json($secteurs);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:10', 'unique:secteurs,code', 'regex:/^[A-Za-z]\d{1,2}$/'],
            'nom' => 'required|string|max:100',
            'description' => 'nullable|string',
        ]);

        $secteur = Secteur::create([
            'code' => strtoupper($request->code),
            'nom' => $request->nom,
            'description' => $request->description,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Secteur créé avec succès',
            'secteur' => $secteur,
        ], 201);
    }

    public function show($id): JsonResponse
    {
        $secteur = Secteur::findOrFail($id);
        return response()->json($secteur);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $secteur = Secteur::findOrFail($id);

        $request->validate([
            'code' => ['sometimes', 'string', 'max:10', 'unique:secteurs,code,' . $id, 'regex:/^[A-Za-z]\d{1,2}$/'],
            'nom' => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'actif' => 'sometimes|boolean',
        ]);

        if ($request->has('code')) {
            $secteur->code = strtoupper($request->code);
        }
        if ($request->has('nom')) {
            $secteur->nom = $request->nom;
        }
        if ($request->has('description')) {
            $secteur->description = $request->description;
        }
        if ($request->has('actif')) {
            $secteur->actif = $request->actif;
        }

        $secteur->save();

        return response()->json([
            'success' => true,
            'message' => 'Secteur modifié avec succès',
            'secteur' => $secteur,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $secteur = Secteur::findOrFail($id);
        $secteur->actif = false;
        $secteur->save();

        return response()->json([
            'success' => true,
            'message' => 'Secteur désactivé avec succès',
        ]);
    }
}
