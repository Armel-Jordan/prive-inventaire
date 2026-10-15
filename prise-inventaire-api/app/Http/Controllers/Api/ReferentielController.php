<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Devise;
use App\Support\Referentiels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * API générique des référentiels (unités, catégories, devises…) : une seule logique CRUD,
 * pilotée par le catalogue App\Support\Referentiels. Les modèles portent BelongsToTenant :
 * lectures et écritures restent bornées au tenant courant.
 */
class ReferentielController extends Controller
{
    public function index(Request $request, string $type): JsonResponse
    {
        $def = Referentiels::definition($type);
        [$colonne, $sens] = $def['ordre'];

        $fiches = $def['modele']::query()
            ->with($def['avec'] ?? [])
            ->when($request->boolean('actif'), fn ($q) => $q->where('actif', true))
            ->orderBy($colonne, $sens)
            ->get();

        return response()->json($fiches);
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $def = Referentiels::definition($type);
        $donnees = $this->valider($request, $def, null);

        $fiche = DB::transaction(function () use ($def, $donnees) {
            $fiche = $def['modele']::create($donnees);
            $this->apresEnregistrement($def, $fiche);

            return $fiche;
        });

        return response()->json($fiche->load($def['avec'] ?? []), 201);
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $def = Referentiels::definition($type);
        $fiche = $def['modele']::findOrFail($id);
        $donnees = $this->valider($request, $def, $fiche);

        DB::transaction(function () use ($def, $fiche, $donnees) {
            $fiche->update($donnees);
            $this->apresEnregistrement($def, $fiche);
        });

        return response()->json($fiche->fresh($def['avec'] ?? []));
    }

    public function destroy(string $type, int $id): JsonResponse
    {
        $def = Referentiels::definition($type);
        $fiche = $def['modele']::findOrFail($id);

        if (isset($def['suppression']) && ($refus = $def['suppression']($fiche))) {
            return response()->json(['message' => $refus], 422);
        }

        $fiche->delete();

        return response()->json(['message' => 'Élément supprimé.']);
    }

    public function historiqueDevise(int $id): JsonResponse
    {
        return response()->json(Devise::findOrFail($id)->historiqueTaux()->get());
    }

    private function valider(Request $request, array $def, ?Model $fiche): array
    {
        foreach ($def['majuscules'] ?? [] as $champ) {
            if (is_string($request->input($champ))) {
                $request->merge([$champ => strtoupper(trim($request->input($champ)))]);
            }
        }

        $donnees = $request->validate($def['regles']($fiche?->getKey()));

        if (isset($def['controle']) && ($refus = $def['controle']($donnees, $fiche))) {
            throw ValidationException::withMessages($refus);
        }

        return $donnees;
    }

    private function apresEnregistrement(array $def, Model $fiche): void
    {
        if (! empty($def['unique_par_defaut']) && $fiche->par_defaut) {
            $def['modele']::whereKeyNot($fiche->getKey())->where('par_defaut', true)->update(['par_defaut' => false]);
        }

        if (isset($def['apres'])) {
            $def['apres']($fiche);
        }
    }
}
