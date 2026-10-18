<?php

namespace App\Http\Controllers\Prix;

use App\Http\Controllers\Controller;
use App\Models\ReglePrix;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Règles de prix : prix client ou d'une catégorie de clients, paliers de quantité, promotions. */
class RegleController extends Controller
{
    private const RELATIONS = ['client:id,code,raison_sociale', 'categorieClient:id,nom', 'produit:id,numero,description', 'listePrix:id,nom'];

    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'produit_id' => ['nullable', 'integer'],
            'statut' => ['nullable', Rule::in(['en_vigueur', 'a_venir', 'expiree', 'inactive'])],
            'recherche' => ['nullable', 'string', 'max:100'],
        ]);
        $aujourdhui = now()->toDateString();

        $regles = ReglePrix::with(self::RELATIONS)
            ->when($filtres['client_id'] ?? null, fn ($q, $client) => $q->where('client_id', $client))
            ->when($filtres['produit_id'] ?? null, fn ($q, $produit) => $q->where('produit_id', $produit))
            ->when($filtres['recherche'] ?? null, fn ($q, $texte) => $q->where('libelle', 'like', "%{$texte}%"))
            ->when($filtres['statut'] ?? null, fn ($q, $statut) => match ($statut) {
                'en_vigueur' => $q->enVigueur(now()),
                'a_venir' => $q->where('actif', true)->whereDate('date_debut', '>', $aujourdhui),
                'expiree' => $q->where('actif', true)->whereDate('date_fin', '<', $aujourdhui),
                'inactive' => $q->where('actif', false),
            })
            ->orderByDesc('actif')->orderBy('libelle')
            ->paginate(50);

        return response()->json($regles);
    }

    public function store(Request $request): JsonResponse
    {
        $regle = ReglePrix::create($this->valider($request));

        return response()->json($regle->load(self::RELATIONS), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $regle = ReglePrix::findOrFail($id);
        $regle->update($this->valider($request));

        return response()->json($regle->load(self::RELATIONS));
    }

    public function destroy(int $id): JsonResponse
    {
        ReglePrix::findOrFail($id)->delete();

        return response()->json(['message' => __('Règle de prix supprimée.')]);
    }

    private function valider(Request $request): array
    {
        $donnees = $request->validate([
            'libelle' => ['required', 'string', 'max:150'],
            'client_id' => ['nullable', 'integer', TenantRule::exists('clients')],
            'categorie_client_id' => ['nullable', 'integer', TenantRule::exists('client_categories')],
            'liste_prix_id' => ['nullable', 'integer', TenantRule::exists('listes_prix')],
            'produit_id' => ['nullable', 'integer', TenantRule::exists('produits')],
            'categorie_produit' => ['nullable', 'string', 'max:100'],
            'quantite_min' => ['nullable', 'integer', 'min:1'],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'type' => ['required', Rule::in(ReglePrix::TYPES)],
            'valeur' => ['required', 'numeric', 'min:0', Rule::when($request->input('type') === 'remise_pourcentage', ['max:100'])],
            'actif' => ['sometimes', 'boolean'],
        ], [
            'date_fin.after_or_equal' => __('La fin doit suivre le début.'),
            'valeur.max' => __('Une remise ne peut pas dépasser 100 %.'),
        ]);
        $donnees['quantite_min'] = $donnees['quantite_min'] ?? 1;
        $donnees['categorie_produit'] = filled($donnees['categorie_produit'] ?? null) ? trim($donnees['categorie_produit']) : null;

        return $donnees;
    }
}
