<?php

namespace App\Http\Controllers;

use App\Models\InventaireScan;
use App\Models\ProduitMobile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScanController extends Controller
{
    public function validerProduit(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'numero' => 'required|string',
            ]);

            $produit = ProduitMobile::where('NUMERO', $request->numero)->first();

            if (! $produit) {
                return response()->json([
                    'valide' => false,
                    'message' => 'Numéro introuvable',
                ], 404);
            }

            return response()->json([
                'valide' => true,
                'numero' => $produit->numero,
                'description' => $produit->description,
                'unite_mesure' => $produit->mesure,
                'type' => $produit->type,
            ]);
        } catch (\Exception $e) {
            \Log::error('Erreur validation produit: '.$e->getMessage());

            return response()->json([
                'valide' => false,
                'message' => 'Erreur serveur: '.$e->getMessage(),
            ], 500);
        }
    }

    public function enregistrer(Request $request): JsonResponse
    {
        $request->validate([
            'numero' => 'required|string',
            'quantite' => 'required|numeric|min:0',
            'employe' => 'required|string',
            'secteur' => ['required', 'string', 'regex:/^[A-Za-z]\d{1,2}$/'],
            'scanneur' => 'nullable|string|max:20',
        ]);

        $produit = ProduitMobile::where('NUMERO', $request->numero)->first();

        if (! $produit) {
            return response()->json([
                'success' => false,
                'message' => 'Numéro de produit introuvable',
            ], 404);
        }

        try {
            DB::connection('oracle')->beginTransaction();

            $scan = InventaireScan::create([
                'NUMERO' => $produit->numero,
                'TYPE' => $produit->type,
                'QUANTITE' => $request->quantite,
                'UNITE_MESURE' => $produit->mesure,
                'EMPLOYE' => $request->employe,
                'SECTEUR' => strtoupper($request->secteur),
                'DATE_SAISIE' => now(),
                'SCANNEUR' => $request->scanneur,
            ]);

            DB::connection('oracle')->commit();

            return response()->json([
                'success' => true,
                'message' => 'Saisie enregistrée',
                'scan' => $scan,
            ]);
        } catch (\Exception $e) {
            DB::connection('oracle')->rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'enregistrement: '.$e->getMessage(),
            ], 500);
        }
    }

    public function historique(Request $request): JsonResponse
    {
        $request->validate([
            'employe' => 'required|string',
            'secteur' => ['required', 'string', 'regex:/^[A-Za-z]\d{1,2}$/'],
        ]);

        $scans = InventaireScan::where('EMPLOYE', $request->employe)
            ->where('SECTEUR', strtoupper($request->secteur))
            ->orderByDesc('DATE_SAISIE')
            ->limit(50)
            ->get();

        return response()->json($scans);
    }

    public function modifier(Request $request, $id): JsonResponse
    {
        $request->validate([
            'quantite' => 'required|numeric|min:0',
        ]);

        try {
            $scan = InventaireScan::findOrFail($id);

            $scan->QUANTITE = $request->quantite;
            $scan->save();
            $scan->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Scan modifié avec succès',
                'scan' => $scan,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification: '.$e->getMessage(),
            ], 500);
        }
    }

    public function supprimer($id): JsonResponse
    {
        try {
            $scan = InventaireScan::findOrFail($id);
            $scan->softDelete();

            return response()->json([
                'success' => true,
                'message' => 'Scan supprimé avec succès',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression: '.$e->getMessage(),
            ], 500);
        }
    }
}
