<?php

namespace App\Http\Controllers\Prix;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ListePrix;
use App\Models\ReglePrix;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Listes de prix et clients qui leur sont rattachés. */
class ListeController extends Controller
{
    public function index(): JsonResponse
    {
        $listes = ListePrix::withCount('prixProduits')
            ->addSelect(['clients_count' => Client::selectRaw('COUNT(*)')->whereColumn('clients.liste_prix_id', 'listes_prix.id')])
            ->orderBy('nom')
            ->get();

        return response()->json($listes);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(ListePrix::create($this->valider($request)), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $liste = ListePrix::findOrFail($id);
        $liste->update($this->valider($request, $liste->id));

        return response()->json($liste);
    }

    /** Refusée tant que des règles visent la liste ; ses clients restent, sans liste. */
    public function destroy(int $id): JsonResponse
    {
        $liste = ListePrix::findOrFail($id);
        if (ReglePrix::where('liste_prix_id', $liste->id)->exists()) {
            return response()->json(['message' => 'Des règles de prix visent cette liste : modifiez-les ou supprimez-les d\'abord.'], 422);
        }

        DB::transaction(function () use ($liste) {
            Client::where('liste_prix_id', $liste->id)->update(['liste_prix_id' => null]);
            $liste->delete();
        });

        return response()->json(['message' => 'Liste de prix supprimée.']);
    }

    public function clients(int $id): JsonResponse
    {
        $liste = ListePrix::findOrFail($id);

        return response()->json(Client::where('liste_prix_id', $liste->id)->orderBy('raison_sociale')->get(['id', 'code', 'raison_sociale', 'ville']));
    }

    /** Rattache un client à une liste (ou l'en détache avec null). */
    public function rattacherClient(Request $request, int $client): JsonResponse
    {
        $client = Client::findOrFail($client);
        $donnees = $request->validate(['liste_prix_id' => ['present', 'nullable', 'integer', TenantRule::exists('listes_prix')]]);
        // Mise à jour directe : la colonne n'est pas dans les champs modifiables de la fiche client.
        Client::whereKey($client->id)->update(['liste_prix_id' => $donnees['liste_prix_id']]);

        return response()->json(['client_id' => $client->id, 'liste_prix_id' => $donnees['liste_prix_id']]);
    }

    private function valider(Request $request, ?int $ignorer = null): array
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:100', TenantRule::unique('listes_prix', 'nom', $ignorer)],
            'description' => ['nullable', 'string', 'max:255'],
            'ajustement_pourcentage' => ['required', 'numeric', 'min:-100', 'max:1000'],
            'precision' => ['required', 'integer', 'min:0', 'max:4'],
            'actif' => ['sometimes', 'boolean'],
        ], ['nom.unique' => 'Une liste de prix porte déjà ce nom.']);
        $donnees['nom'] = trim($donnees['nom']);

        return $donnees;
    }
}
