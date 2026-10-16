<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientRemarque;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Remarques datées d'un client. Seuls leur auteur et les administrateurs les modifient ou les suppriment. */
class ClientFicheRemarqueController extends Controller
{
    public function index(int $client): JsonResponse
    {
        $client = Client::findOrFail($client);

        return response()->json($client->remarques()->with('auteur:id,nom')->get());
    }

    public function store(Request $request, int $client): JsonResponse
    {
        $client = Client::findOrFail($client);
        $donnees = $request->validate([
            'texte' => ['required', 'string', 'max:5000'],
            'epinglee' => ['sometimes', 'boolean'],
        ]);

        $remarque = ClientRemarque::create([...$donnees, 'client_id' => $client->id, 'auteur_id' => $request->user()->id]);

        return response()->json($remarque->load('auteur:id,nom'), 201);
    }

    public function update(Request $request, int $client, int $id): JsonResponse
    {
        $remarque = $this->remarque($request, $client, $id);
        $remarque->update($request->validate([
            'texte' => ['sometimes', 'required', 'string', 'max:5000'],
            'epinglee' => ['sometimes', 'boolean'],
        ]));

        return response()->json($remarque->load('auteur:id,nom'));
    }

    public function destroy(Request $request, int $client, int $id): JsonResponse
    {
        $this->remarque($request, $client, $id)->delete();

        return response()->json(['message' => 'Remarque supprimée.']);
    }

    private function remarque(Request $request, int $client, int $id): ClientRemarque
    {
        $client = Client::findOrFail($client);
        $remarque = ClientRemarque::where('client_id', $client->id)->findOrFail($id);
        $utilisateur = $request->user();
        abort_unless((int) $remarque->auteur_id === (int) $utilisateur->id || $utilisateur->isAdmin(), 403, 'Seul son auteur ou un administrateur peut modifier cette remarque.');

        return $remarque;
    }
}
