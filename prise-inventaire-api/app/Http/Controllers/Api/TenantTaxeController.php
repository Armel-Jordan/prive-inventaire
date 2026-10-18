<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GroupeTaxeLigne;
use App\Models\TenantTaxe;
use App\Support\TauxTaxe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantTaxeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $taxes = TenantTaxe::where('tenant_id', $tenantId)->orderBy('taux')->get();

        return response()->json($taxes);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'required|string|max:100',
            'taux' => 'required|numeric|min:0|max:100',
            'par_defaut' => 'boolean',
        ]);

        $tenantId = $request->attributes->get('tenant')->id;

        if (! empty($validated['par_defaut'])) {
            TenantTaxe::where('tenant_id', $tenantId)->update(['par_defaut' => false]);
        }

        $taxe = TenantTaxe::create(array_merge($validated, ['tenant_id' => $tenantId]));
        $this->appliquerParDefaut($taxe, $tenantId);

        return response()->json($taxe, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $taxe = TenantTaxe::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'nom' => 'required|string|max:100',
            'taux' => 'required|numeric|min:0|max:100',
            'par_defaut' => 'boolean',
        ]);

        if (! empty($validated['par_defaut'])) {
            TenantTaxe::where('tenant_id', $tenantId)->where('id', '!=', $id)->update(['par_defaut' => false]);
        }

        $taxe->update($validated);
        $this->appliquerParDefaut($taxe, $tenantId);

        return response()->json($taxe);
    }

    /**
     * La taxe par défaut fixe le taux par défaut de l'entreprise, appliqué aux lignes sans taux ni groupe de
     * taxes. « Définir par défaut » ne changeait rien : les documents restaient à 20 %.
     */
    private function appliquerParDefaut(TenantTaxe $taxe, int $tenantId): void
    {
        if ($taxe->par_defaut) {
            TauxTaxe::definir($tenantId, (float) $taxe->taux);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $taxe = TenantTaxe::where('tenant_id', $tenantId)->findOrFail($id);

        if (GroupeTaxeLigne::where('tenant_taxe_id', $taxe->id)->exists()) {
            return response()->json(['message' => __('Cette taxe est utilisée dans un groupe de taxes.')], 422);
        }

        $taxe->delete();

        return response()->json(['message' => __('Taxe supprimée.')]);
    }
}
