<?php

namespace App\Http\Controllers;

use App\Models\EmployeTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeTenantController extends Controller
{
    public function index(): JsonResponse
    {
        $employes = EmployeTenant::where('actif', true)
            ->orderBy('nom')
            ->get();

        return response()->json($employes);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'numero' => 'required|string|max:20|unique:employes,numero',
            'nom' => 'required|string|max:100',
            'prenom' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:100',
        ]);

        $employe = EmployeTenant::create([
            'numero' => $request->numero,
            'nom' => $request->nom,
            'prenom' => $request->prenom,
            'email' => $request->email,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Employé créé avec succès',
            'employe' => $employe,
        ], 201);
    }

    public function show($id): JsonResponse
    {
        $employe = EmployeTenant::findOrFail($id);
        return response()->json($employe);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $employe = EmployeTenant::findOrFail($id);

        $request->validate([
            'numero' => 'sometimes|string|max:20|unique:employes,numero,' . $id,
            'nom' => 'sometimes|string|max:100',
            'prenom' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:100',
            'actif' => 'sometimes|boolean',
        ]);

        $employe->fill($request->only(['numero', 'nom', 'prenom', 'email', 'actif']));
        $employe->save();

        return response()->json([
            'success' => true,
            'message' => 'Employé modifié avec succès',
            'employe' => $employe,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $employe = EmployeTenant::findOrFail($id);
        $employe->actif = false;
        $employe->save();

        return response()->json([
            'success' => true,
            'message' => 'Employé désactivé avec succès',
        ]);
    }
}
