<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TenantTaxe;
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

        return response()->json($taxe);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $taxe = TenantTaxe::where('tenant_id', $tenantId)->findOrFail($id);
        $taxe->delete();

        return response()->json(['message' => 'Taxe supprimée.']);
    }
}
