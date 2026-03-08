<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Configuration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfigurationController extends Controller
{
    /**
     * Liste toutes les configurations
     */
    public function index(): JsonResponse
    {
        $configurations = Configuration::all();

        // Si aucune configuration, créer les défauts
        if ($configurations->isEmpty()) {
            $defaults = [
                ['entite' => 'produit', 'prefixe' => 'P', 'longueur' => 5, 'auto_increment' => true, 'prochain_numero' => 1],
                ['entite' => 'employe', 'prefixe' => 'E', 'longueur' => 4, 'auto_increment' => true, 'prochain_numero' => 1],
                ['entite' => 'secteur', 'prefixe' => '', 'longueur' => 3, 'separateur' => '-', 'auto_increment' => false, 'prochain_numero' => 1],
            ];

            foreach ($defaults as $default) {
                Configuration::create($default);
            }

            $configurations = Configuration::all();
        }

        return response()->json($configurations);
    }

    /**
     * Récupère une configuration par entité
     */
    public function show(string $entite): JsonResponse
    {
        $configuration = Configuration::where('entite', $entite)->first();

        if (!$configuration) {
            return response()->json(['message' => 'Configuration non trouvée'], 404);
        }

        return response()->json($configuration);
    }

    /**
     * Met à jour une configuration
     */
    public function update(Request $request, string $entite): JsonResponse
    {
        $request->validate([
            'prefixe' => 'nullable|string|max:10',
            'suffixe' => 'nullable|string|max:10',
            'longueur' => 'required|integer|min:2|max:8',
            'separateur' => 'nullable|string|max:5',
            'auto_increment' => 'required|boolean',
            'prochain_numero' => 'required|integer|min:1',
        ]);

        $configuration = Configuration::updateOrCreate(
            ['entite' => $entite],
            [
                'prefixe' => $request->prefixe ?? '',
                'suffixe' => $request->suffixe ?? '',
                'longueur' => $request->longueur,
                'separateur' => $request->separateur ?? '',
                'auto_increment' => $request->auto_increment,
                'prochain_numero' => $request->prochain_numero,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Configuration mise à jour',
            'configuration' => $configuration,
        ]);
    }

    /**
     * Génère le prochain numéro pour une entité
     */
    public function genererNumero(string $entite): JsonResponse
    {
        $configuration = Configuration::where('entite', $entite)->first();

        if (!$configuration) {
            return response()->json(['message' => 'Configuration non trouvée'], 404);
        }

        if (!$configuration->auto_increment) {
            return response()->json([
                'message' => 'La numérotation automatique n\'est pas activée pour cette entité',
            ], 400);
        }

        $numero = $configuration->genererNumero();

        return response()->json([
            'numero' => $numero,
            'prochain' => $configuration->prochain_numero,
        ]);
    }

    /**
     * Génère et consomme le prochain numéro (incrémente le compteur)
     */
    public function consommerNumero(string $entite): JsonResponse
    {
        $configuration = Configuration::where('entite', $entite)->first();

        if (!$configuration) {
            return response()->json(['message' => 'Configuration non trouvée'], 404);
        }

        if (!$configuration->auto_increment) {
            return response()->json([
                'message' => 'La numérotation automatique n\'est pas activée pour cette entité',
            ], 400);
        }

        $numero = $configuration->genererNumero();
        $configuration->incrementer();

        return response()->json([
            'numero' => $numero,
        ]);
    }
}
