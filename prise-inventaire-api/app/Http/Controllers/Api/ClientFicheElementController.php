<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Listes rattachées à une fiche client (contacts, adresses) : même cycle de vie, et au plus un
 * élément « principal » par client. Chaque liste fournit son modèle, ses règles et sa colonne principale.
 */
abstract class ClientFicheElementController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function modele(): string;

    abstract protected function regles(): array;

    abstract protected function colonnePrincipale(): string;

    abstract protected function ordre(): string;

    public function index(int $client): JsonResponse
    {
        $client = Client::findOrFail($client);

        return response()->json($this->modele()::where('client_id', $client->id)
            ->orderByDesc($this->colonnePrincipale())->orderBy($this->ordre())->get());
    }

    public function store(Request $request, int $client): JsonResponse
    {
        $client = Client::findOrFail($client);
        $donnees = $request->validate($this->regles());

        $element = DB::transaction(function () use ($client, $donnees) {
            $element = $this->modele()::create([...$donnees, 'client_id' => $client->id]);
            $this->garderUnSeulPrincipal($element);

            return $element;
        });

        return response()->json($element->refresh(), 201);
    }

    public function update(Request $request, int $client, int $id): JsonResponse
    {
        $element = $this->element($client, $id);
        $donnees = $request->validate($this->regles());

        DB::transaction(function () use ($element, $donnees) {
            $element->update($donnees);
            $this->garderUnSeulPrincipal($element);
        });

        return response()->json($element->refresh());
    }

    public function destroy(int $client, int $id): JsonResponse
    {
        $this->element($client, $id)->delete();

        return response()->json(['message' => 'Supprimé.']);
    }

    private function element(int $client, int $id): Model
    {
        $client = Client::findOrFail($client);

        return $this->modele()::where('client_id', $client->id)->findOrFail($id);
    }

    private function garderUnSeulPrincipal(Model $element): void
    {
        $colonne = $this->colonnePrincipale();
        if ($element->{$colonne}) {
            $this->modele()::where('client_id', $element->client_id)->whereKeyNot($element->getKey())->update([$colonne => false]);
        }
    }
}
