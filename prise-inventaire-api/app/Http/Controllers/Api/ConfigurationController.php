<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Configuration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfigurationController extends Controller
{
    private function getTenantId(Request $request): int
    {
        return $request->attributes->get('tenant')->id;
    }

    private array $defaults = [
        ['entite' => 'produit',     'prefixe' => 'P',  'suffixe' => '', 'longueur' => 5, 'separateur' => '', 'auto_increment' => true,  'prochain_numero' => 1],
        ['entite' => 'employe',     'prefixe' => 'E',  'suffixe' => '', 'longueur' => 4, 'separateur' => '', 'auto_increment' => true,  'prochain_numero' => 1],
        ['entite' => 'secteur',     'prefixe' => '',   'suffixe' => '', 'longueur' => 3, 'separateur' => '-','auto_increment' => false, 'prochain_numero' => 1],
        ['entite' => 'fournisseur', 'prefixe' => 'F',  'suffixe' => '', 'longueur' => 5, 'separateur' => '', 'auto_increment' => true,  'prochain_numero' => 1],
        ['entite' => 'client',      'prefixe' => 'C',  'suffixe' => '', 'longueur' => 5, 'separateur' => '', 'auto_increment' => true,  'prochain_numero' => 1],
        ['entite' => 'commande',    'prefixe' => 'CMD','suffixe' => '', 'longueur' => 5, 'separateur' => '-','auto_increment' => true,  'prochain_numero' => 1],
        ['entite' => 'facture',     'prefixe' => 'FAC','suffixe' => '', 'longueur' => 5, 'separateur' => '-','auto_increment' => true,  'prochain_numero' => 1],
        ['entite' => 'bon_livraison','prefixe'=> 'BL', 'suffixe' => '', 'longueur' => 5, 'separateur' => '-','auto_increment' => true,  'prochain_numero' => 1],
        ['entite' => 'tournee',     'prefixe' => 'T',  'suffixe' => '', 'longueur' => 4, 'separateur' => '', 'auto_increment' => true,  'prochain_numero' => 1],
    ];

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        $configurations = Configuration::where('tenant_id', $tenantId)->get();

        if ($configurations->isEmpty()) {
            foreach ($this->defaults as $default) {
                Configuration::create(array_merge($default, ['tenant_id' => $tenantId]));
            }
            $configurations = Configuration::where('tenant_id', $tenantId)->get();
        }

        return response()->json($configurations);
    }

    public function show(Request $request, string $entite): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        $configuration = Configuration::where('tenant_id', $tenantId)->where('entite', $entite)->first();

        if (!$configuration) {
            return response()->json(['message' => 'Configuration non trouvée'], 404);
        }

        return response()->json($configuration);
    }

    public function update(Request $request, string $entite): JsonResponse
    {
        $request->validate([
            'prefixe'         => 'nullable|string|max:10',
            'suffixe'         => 'nullable|string|max:10',
            'longueur'        => 'required|integer|min:2|max:8',
            'separateur'      => 'nullable|string|max:5',
            'auto_increment'  => 'required|boolean',
            'prochain_numero' => 'required|integer|min:1',
        ]);

        $tenantId = $this->getTenantId($request);

        $configuration = Configuration::updateOrCreate(
            ['tenant_id' => $tenantId, 'entite' => $entite],
            [
                'prefixe'         => $request->prefixe ?? '',
                'suffixe'         => $request->suffixe ?? '',
                'longueur'        => $request->longueur,
                'separateur'      => $request->separateur ?? '',
                'auto_increment'  => $request->auto_increment,
                'prochain_numero' => $request->prochain_numero,
            ]
        );

        return response()->json([
            'success'       => true,
            'message'       => 'Configuration mise à jour',
            'configuration' => $configuration,
        ]);
    }

    public function genererNumero(Request $request, string $entite): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        $configuration = Configuration::where('tenant_id', $tenantId)->where('entite', $entite)->first();

        if (!$configuration) {
            return response()->json(['message' => 'Configuration non trouvée'], 404);
        }

        if (!$configuration->auto_increment) {
            return response()->json(['message' => 'La numérotation automatique n\'est pas activée'], 400);
        }

        $numero = $configuration->genererNumero();

        return response()->json([
            'numero'  => $numero,
            'prochain' => $configuration->prochain_numero,
        ]);
    }

    public function consommerNumero(Request $request, string $entite): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        $configuration = Configuration::where('tenant_id', $tenantId)->where('entite', $entite)->first();

        if (!$configuration) {
            return response()->json(['message' => 'Configuration non trouvée'], 404);
        }

        if (!$configuration->auto_increment) {
            return response()->json(['message' => 'La numérotation automatique n\'est pas activée'], 400);
        }

        $numero = $configuration->genererNumero();
        $configuration->incrementer();

        return response()->json(['numero' => $numero]);
    }
}
