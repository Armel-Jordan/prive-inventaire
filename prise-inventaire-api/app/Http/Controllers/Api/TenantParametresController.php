<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TenantParametres;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantParametresController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;

        $parametres = TenantParametres::firstOrCreate(
            ['tenant_id' => $tenantId],
            [
                'devise_symbole' => '€',
                'devise_code' => 'EUR',
                'tva_taux' => 20.00,
                'delai_paiement_jours' => 30,
                'delai_livraison_jours' => 7,
                'stock_seuil_defaut' => 5,
            ]
        );

        return response()->json($parametres);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom_entreprise' => 'nullable|string|max:255',
            'adresse' => 'nullable|string|max:500',
            'telephone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'siret' => 'nullable|string|max:20',
            'tva_numero' => 'nullable|string|max:50',
            'logo_url' => 'nullable|string|max:500',
            'devise_symbole' => 'nullable|string|max:5',
            'devise_code' => 'nullable|string|max:5',
            'tva_taux' => 'nullable|numeric|min:0|max:100',
            'delai_paiement_jours' => 'nullable|integer|min:0|max:365',
            'delai_livraison_jours' => 'nullable|integer|min:0|max:365',
            'stock_alerte_email' => 'nullable|email|max:255',
            'stock_seuil_defaut' => 'nullable|integer|min:0',
        ]);

        $tenantId = $request->attributes->get('tenant')->id;

        $parametres = TenantParametres::updateOrCreate(
            ['tenant_id' => $tenantId],
            $validated
        );

        return response()->json([
            'message' => 'Paramètres mis à jour avec succès.',
            'parametres' => $parametres,
        ]);
    }
}
