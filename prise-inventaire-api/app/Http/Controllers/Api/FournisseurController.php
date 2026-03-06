<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fournisseur;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class FournisseurController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Fournisseur::query();

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('raison_sociale', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('actif')) {
            $query->where('actif', $request->boolean('actif'));
        }

        $fournisseurs = $query->orderBy('raison_sociale')->paginate($request->get('per_page', 15));

        return response()->json($fournisseurs);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'nullable|string|max:20|unique:fournisseurs,code',
            'raison_sociale' => 'required|string|max:255',
            'adresse' => 'nullable|string',
            'telephone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'contact_nom' => 'nullable|string|max:100',
            'contact_telephone' => 'nullable|string|max:20',
            'conditions_paiement' => 'nullable|string|max:100',
            'actif' => 'boolean',
        ]);

        if (empty($validated['code'])) {
            $validated['code'] = Fournisseur::generateCode();
        }

        $fournisseur = Fournisseur::create($validated);

        return response()->json($fournisseur, 201);
    }

    public function show(Fournisseur $fournisseur): JsonResponse
    {
        $fournisseur->load(['produits', 'commandes' => function ($query) {
            $query->latest()->limit(10);
        }]);

        return response()->json($fournisseur);
    }

    public function update(Request $request, Fournisseur $fournisseur): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:20', Rule::unique('fournisseurs', 'code')->ignore($fournisseur->id)],
            'raison_sociale' => 'required|string|max:255',
            'adresse' => 'nullable|string',
            'telephone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'contact_nom' => 'nullable|string|max:100',
            'contact_telephone' => 'nullable|string|max:20',
            'conditions_paiement' => 'nullable|string|max:100',
            'actif' => 'boolean',
        ]);

        $fournisseur->update($validated);

        return response()->json($fournisseur);
    }

    public function destroy(Fournisseur $fournisseur): JsonResponse
    {
        if ($fournisseur->commandes()->exists()) {
            return response()->json([
                'message' => 'Impossible de supprimer ce fournisseur car il a des commandes associées.'
            ], 422);
        }

        $fournisseur->delete();

        return response()->json(['message' => 'Fournisseur supprimé avec succès.']);
    }

    public function listActifs(): JsonResponse
    {
        $fournisseurs = Fournisseur::where('actif', true)
            ->orderBy('raison_sociale')
            ->get(['id', 'code', 'raison_sociale']);

        return response()->json($fournisseurs);
    }
}
