<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientProduit;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Références des produits chez un client (plan de parité fripv2, phase 1.2c) : son numéro d'article pour chaque produit
 * qu'il achète, et au besoin sa désignation. Une seule référence par produit, une référence ne désigne qu'un produit.
 */
class ClientFicheReferenceController extends Controller
{
    private const RELATIONS = ['produit:id,numero,description,mesure'];

    public function index(int $client): JsonResponse
    {
        $client = Client::findOrFail($client);

        return response()->json($client->referencesProduits()->with(self::RELATIONS)->get());
    }

    public function store(Request $request, int $client): JsonResponse
    {
        $client = Client::findOrFail($client);
        $reference = ClientProduit::create([...$this->valider($request, $client), 'client_id' => $client->id]);

        return response()->json($reference->load(self::RELATIONS), 201);
    }

    public function update(Request $request, int $client, int $id): JsonResponse
    {
        $client = Client::findOrFail($client);
        $reference = $client->referencesProduits()->findOrFail($id);
        $reference->update($this->valider($request, $client, $reference));

        return response()->json($reference->load(self::RELATIONS));
    }

    public function destroy(int $client, int $id): JsonResponse
    {
        Client::findOrFail($client)->referencesProduits()->findOrFail($id)->delete();

        return response()->json(['message' => __('Référence supprimée.')]);
    }

    private function valider(Request $request, Client $client, ?ClientProduit $reference = null): array
    {
        $request->merge(['reference' => trim((string) $request->input('reference'))]);
        $donnees = $request->validate([
            'produit_id' => ['required', 'integer', TenantRule::exists('produits'),
                Rule::unique('client_produits')->where('client_id', $client->id)->ignore($reference?->id)],
            'reference' => ['required', 'string', 'max:60',
                Rule::unique('client_produits')->where('client_id', $client->id)->ignore($reference?->id)],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'produit_id.unique' => __('Ce produit a déjà une référence chez ce client.'),
            'reference.unique' => __('Cette référence désigne déjà un autre produit chez ce client.'),
        ]);
        $donnees['description'] = filled($donnees['description'] ?? null) ? trim($donnees['description']) : null;

        return $donnees;
    }
}
