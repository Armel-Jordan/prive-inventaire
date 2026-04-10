<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientConditionPaiement;
use App\Models\Configuration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $query = Client::where('tenant_id', $tenantId);

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('raison_sociale', 'like', "%{$search}%")
                  ->orWhere('ville', 'like', "%{$search}%");
            });
        }

        if ($request->has('actif')) {
            $query->where('actif', $request->boolean('actif'));
        }

        $clients = $query->orderBy('raison_sociale')->paginate($request->get('per_page', 20));
        return response()->json($clients);
    }

    public function actifs(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $clients = Client::where('tenant_id', $tenantId)->where('actif', true)->orderBy('raison_sociale')->get();
        return response()->json($clients);
    }

    public function show(int $id): JsonResponse
    {
        $client = Client::with('conditionsPaiement')->findOrFail($id);
        return response()->json($client);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'raison_sociale' => 'required|string|max:255',
            'adresse_facturation' => 'required|string',
            'adresse_livraison' => 'nullable|string',
            'ville' => 'required|string|max:100',
            'code_postal' => 'required|string|max:10',
            'telephone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'contact_nom' => 'nullable|string|max:100',
            'contact_telephone' => 'nullable|string|max:20',
            'encours_max' => 'nullable|numeric|min:0',
            'taux_remise_global' => 'nullable|numeric|min:0|max:100',
        ]);

        $tenantId = $request->attributes->get('tenant')->id;
        $config = Configuration::pourEntite('client', $tenantId);
        if ($config && $config->auto_increment) {
            $validated['code'] = $config->genererNumero();
            $config->incrementer();
        } else {
            return response()->json(['success' => false, 'message' => 'Numéro requis'], 422);
        }
        $validated['actif'] = true;
        $validated['tenant_id'] = $tenantId;

        $client = Client::create($validated);
        return response()->json($client, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $client = Client::findOrFail($id);

        $validated = $request->validate([
            'raison_sociale' => 'sometimes|string|max:255',
            'adresse_facturation' => 'sometimes|string',
            'adresse_livraison' => 'nullable|string',
            'ville' => 'sometimes|string|max:100',
            'code_postal' => 'sometimes|string|max:10',
            'telephone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'contact_nom' => 'nullable|string|max:100',
            'contact_telephone' => 'nullable|string|max:20',
            'encours_max' => 'nullable|numeric|min:0',
            'taux_remise_global' => 'nullable|numeric|min:0|max:100',
            'actif' => 'sometimes|boolean',
        ]);

        $client->update($validated);
        return response()->json($client);
    }

    public function destroy(int $id): JsonResponse
    {
        $client = Client::findOrFail($id);

        if ($client->commandes()->exists()) {
            return response()->json(['message' => 'Impossible de supprimer un client avec des commandes'], 422);
        }

        $client->delete();
        return response()->json(['message' => 'Client supprimé']);
    }

    public function getConditionsPaiement(int $id): JsonResponse
    {
        $client = Client::findOrFail($id);
        return response()->json($client->conditionsPaiement);
    }

    public function setConditionsPaiement(Request $request, int $id): JsonResponse
    {
        $client = Client::findOrFail($id);

        $validated = $request->validate([
            'conditions' => 'required|array|min:1',
            'conditions.*.libelle' => 'required|string|max:100',
            'conditions.*.nb_jours' => 'required|integer|min:0',
            'conditions.*.pourcentage' => 'required|numeric|min:0|max:100',
        ]);

        $totalPourcentage = collect($validated['conditions'])->sum('pourcentage');
        if (abs($totalPourcentage - 100) > 0.01) {
            return response()->json(['message' => 'Le total des pourcentages doit être égal à 100%'], 422);
        }

        DB::transaction(function () use ($client, $validated) {
            $client->conditionsPaiement()->delete();

            foreach ($validated['conditions'] as $index => $condition) {
                $client->conditionsPaiement()->create([
                    'libelle' => $condition['libelle'],
                    'nb_jours' => $condition['nb_jours'],
                    'pourcentage' => $condition['pourcentage'],
                    'ordre' => $index + 1,
                ]);
            }
        });

        return response()->json($client->conditionsPaiement()->get());
    }
}
