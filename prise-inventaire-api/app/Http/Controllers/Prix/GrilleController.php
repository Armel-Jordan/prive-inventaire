<?php

namespace App\Http\Controllers\Prix;

use App\Http\Controllers\Controller;
use App\Models\ListePrix;
use App\Models\ListePrixProduit;
use App\Models\ProduitTenant;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Grille de prix : pour chaque produit, son prix de base (produits.prix_unitaire) et son prix dans chaque
 * liste active, saisi ou calculé par la formule de la liste.
 */
class GrilleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'recherche' => ['nullable', 'string', 'max:100'],
            'categorie' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $listes = ListePrix::where('actif', true)->orderBy('nom')->get();

        $produits = ProduitTenant::query()
            ->when($filtres['recherche'] ?? null, fn ($q, $texte) => $q->where(fn ($q) => $q
                ->where('numero', 'like', "%{$texte}%")->orWhere('description', 'like', "%{$texte}%")))
            ->when($filtres['categorie'] ?? null, fn ($q, $categorie) => $q->where('categorie', $categorie))
            ->orderBy('numero')
            ->paginate($filtres['per_page'] ?? 25, ['id', 'numero', 'description', 'categorie', 'prix_unitaire', 'actif']);

        $saisis = ListePrixProduit::whereIn('liste_prix_id', $listes->pluck('id'))
            ->whereIn('produit_id', $produits->getCollection()->pluck('id'))
            ->get()
            ->groupBy('produit_id');

        $produits->through(function (ProduitTenant $produit) use ($listes, $saisis) {
            $base = $produit->prix_unitaire !== null ? (float) $produit->prix_unitaire : null;
            $prixSaisis = $saisis->get($produit->id, collect())->pluck('prix', 'liste_prix_id');

            return [
                'id' => $produit->id,
                'numero' => $produit->numero,
                'description' => $produit->description,
                'categorie' => $produit->categorie,
                'actif' => (bool) $produit->actif,
                'prix_base' => $base,
                'prix' => $listes->mapWithKeys(fn (ListePrix $liste) => [$liste->id => [
                    'prix' => $liste->prixPour($base, $prixSaisis->get($liste->id)),
                    'saisi' => $prixSaisis->has($liste->id),
                    // Prix que donnerait la formule de la liste, affiché à côté d'un prix saisi.
                    'formule' => $liste->prixPour($base, null),
                ]]),
            ];
        });

        return response()->json([
            ...$produits->toArray(),
            'listes' => $listes,
            'categories' => ProduitTenant::whereNotNull('categorie')->where('categorie', '!=', '')->distinct()->orderBy('categorie')->pluck('categorie'),
        ]);
    }

    /**
     * Modifications groupées : liste_prix_id vide = prix de base du produit ; sur une liste, un prix vide
     * retire le prix saisi (le produit reprend la formule de la liste).
     */
    public function update(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'modifications' => ['required', 'array', 'min:1', 'max:500'],
            'modifications.*.produit_id' => ['required', 'integer', TenantRule::exists('produits')],
            'modifications.*.liste_prix_id' => ['nullable', 'integer', TenantRule::exists('listes_prix')],
            'modifications.*.prix' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
        ]);

        DB::transaction(function () use ($donnees) {
            foreach ($donnees['modifications'] as $modification) {
                $prix = $modification['prix'] ?? null;
                $liste = $modification['liste_prix_id'] ?? null;
                if ($liste === null) {
                    ProduitTenant::whereKey($modification['produit_id'])->update(['prix_unitaire' => $prix]);
                } elseif ($prix === null) {
                    ListePrixProduit::where('liste_prix_id', $liste)->where('produit_id', $modification['produit_id'])->delete();
                } else {
                    ListePrixProduit::updateOrCreate(['liste_prix_id' => $liste, 'produit_id' => $modification['produit_id']], ['prix' => $prix]);
                }
            }
        });

        return response()->json(['message' => 'Prix enregistrés.', 'modifications' => count($donnees['modifications'])]);
    }
}
