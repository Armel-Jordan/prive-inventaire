<?php

namespace App\Http\Controllers;

use App\Models\Configuration;
use App\Models\ProduitTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProduitTenantController extends Controller
{
    public function index(): JsonResponse
    {
        $produits = ProduitTenant::with('secteur')
            ->where('actif', true)
            ->where('tenant_id', auth()->user()->tenant_id)
            ->orderBy('numero')
            ->get();

        return response()->json($produits);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'numero' => 'nullable|string|max:50|unique:produits,numero',
            'description' => 'required|string|max:255',
            'mesure' => 'sometimes|string|max:20',
            'type' => 'nullable|string|max:50',
            'secteur_id' => 'required|exists:secteurs,id',
            'categorie' => 'nullable|string|max:100',
            'prix_unitaire' => 'nullable|numeric|min:0',
        ]);

        // Générer le numéro automatiquement si non fourni
        $numero = $request->numero;
        if (empty($numero)) {
            $config = Configuration::pourEntite('produit', $request->attributes->get('tenant')->id);
            if ($config && $config->auto_increment) {
                $numero = $config->genererNumero();
                $config->incrementer();
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Le numéro est requis (pas de configuration auto-increment)',
                ], 422);
            }
        }

        $produit = ProduitTenant::create([
            'tenant_id' => auth()->user()->tenant_id,
            'numero' => $numero,
            'description' => $request->description,
            'mesure' => $request->mesure ?? 'UN',
            'type' => $request->type,
            'secteur_id' => $request->secteur_id,
            'categorie' => $request->categorie,
            'prix_unitaire' => $request->prix_unitaire,
        ]);

        $produit->load('secteur');

        return response()->json([
            'success' => true,
            'message' => 'Produit créé avec succès',
            'produit' => $produit,
        ], 201);
    }

    public function show($id): JsonResponse
    {
        $produit = ProduitTenant::with('secteur')->findOrFail($id);
        return response()->json($produit);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $produit = ProduitTenant::findOrFail($id);

        $request->validate([
            'numero' => 'sometimes|string|max:50|unique:produits,numero,' . $id,
            'description' => 'sometimes|string|max:255',
            'mesure' => 'sometimes|string|max:20',
            'type' => 'nullable|string|max:50',
            'secteur_id' => 'sometimes|exists:secteurs,id',
            'categorie' => 'nullable|string|max:100',
            'prix_unitaire' => 'nullable|numeric|min:0',
            'actif' => 'sometimes|boolean',
        ]);

        $produit->fill($request->only(['numero', 'description', 'mesure', 'type', 'secteur_id', 'categorie', 'prix_unitaire', 'actif']));
        $produit->save();

        return response()->json([
            'success' => true,
            'message' => 'Produit modifié avec succès',
            'produit' => $produit,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $produit = ProduitTenant::findOrFail($id);
        $produit->actif = false;
        $produit->save();

        return response()->json([
            'success' => true,
            'message' => 'Produit désactivé avec succès',
        ]);
    }

    /**
     * Valide qu'un numéro de produit existe dans la base
     * Utilisé par l'app mobile pour vérifier un code-barres scanné
     */
    public function valider(Request $request): JsonResponse
    {
        $request->validate([
            'numero' => 'required|string',
        ]);

        $numero = $request->input('numero');
        $produit = ProduitTenant::where('numero', $numero)
            ->where('actif', true)
            ->first();

        if (!$produit) {
            return response()->json([
                'valide' => false,
                'message' => 'Produit introuvable',
            ], 404);
        }

        return response()->json([
            'valide' => true,
            'numero' => $produit->numero,
            'description' => $produit->description,
            'unite_mesure' => $produit->mesure,
            'type' => $produit->type,
        ]);
    }
}
