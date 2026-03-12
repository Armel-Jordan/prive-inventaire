<?php

namespace App\Http\Controllers;

use App\Models\Configuration;
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
            'code' => ['nullable', 'string', 'max:10', 'unique:secteurs,code'],
            'nom' => 'required|string|max:100',
            'description' => 'nullable|string',
        ]);

        // Générer le code automatiquement si non fourni
        $code = $request->code;
        if (empty($code)) {
            $config = Configuration::pourEntite('secteur');
            if ($config && $config->auto_increment) {
                $code = $config->genererNumero();
                $config->incrementer();
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Le code est requis (pas de configuration auto-increment)',
                ], 422);
            }
        }

        $secteur = Secteur::create([
            'code' => strtoupper($code),
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

    /**
     * Valider un QR code secteur
     */
    public function validateQrCode(Request $request): JsonResponse
    {
        $request->validate([
            'qr_code' => 'required|string',
        ]);

        $secteur = Secteur::where('qr_code', $request->qr_code)
            ->where('actif', true)
            ->first();

        if (!$secteur) {
            return response()->json([
                'valide' => false,
                'message' => 'QR code secteur non reconnu',
            ], 404);
        }

        return response()->json([
            'valide' => true,
            'secteur' => $secteur,
        ]);
    }

    /**
     * Générer un QR code pour un secteur
     */
    public function generateQrCode($id): JsonResponse
    {
        $secteur = Secteur::findOrFail($id);

        // Générer un code unique basé sur le code secteur
        $qrCode = 'SECT-' . strtoupper($secteur->code) . '-' . substr(md5($secteur->id . time()), 0, 8);

        $secteur->qr_code = $qrCode;
        $secteur->save();

        return response()->json([
            'success' => true,
            'qr_code' => $qrCode,
            'secteur' => $secteur,
        ]);
    }

    /**
     * Mettre à jour le QR code d'un secteur
     */
    public function updateQrCode(Request $request, $id): JsonResponse
    {
        $secteur = Secteur::findOrFail($id);

        $request->validate([
            'qr_code' => 'required|string|max:100|unique:secteurs,qr_code,' . $id,
        ]);

        $secteur->qr_code = $request->qr_code;
        $secteur->save();

        return response()->json([
            'success' => true,
            'message' => 'QR code mis à jour',
            'secteur' => $secteur,
        ]);
    }
}
