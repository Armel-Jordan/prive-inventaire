<?php

namespace App\Http\Controllers;

use App\Models\Configuration;
use App\Models\ProduitTenant;
use App\Support\TenantRule;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProduitTenantController extends Controller
{
    /**
     * Produits actifs (listes de choix, application mobile) ; ?statut=inactifs ou ?statut=tous pour retrouver les
     * produits désactivés, qui disparaissaient de l'écran sans moyen de les réactiver.
     */
    public function index(Request $request): JsonResponse
    {
        $statut = $request->query('statut', 'actifs');
        $produits = ProduitTenant::with('secteur')
            ->when($statut !== 'tous', fn ($q) => $q->where('actif', $statut !== 'inactifs'))
            ->where('tenant_id', auth()->user()->tenant_id)
            ->orderBy('numero')
            ->get();

        return response()->json($produits);
    }

    /**
     * Numéro déjà pris par un produit désactivé : l'utilisateur est invité à le réactiver (le message générique
     * « The numero has already been taken » laissait croire à une erreur).
     */
    private function numeroNonDesactive(?int $ignorer = null): Closure
    {
        return function (string $attribut, mixed $valeur, Closure $echec) use ($ignorer) {
            $desactive = ProduitTenant::where('numero', $valeur)->where('actif', false)
                ->when($ignorer, fn ($q) => $q->whereKeyNot($ignorer))->first();
            if ($desactive) {
                $echec("Le numéro « {$valeur} » appartient au produit désactivé « {$desactive->description} » : réactivez-le (filtre « Désactivés » de la liste des produits).");
            }
        };
    }

    /** Champs de transport : matière dangereuse (numéro ONU, classe, groupe d'emballage), poids et volume unitaires. */
    private function reglesTransport(): array
    {
        return [
            'numero_onu' => ['nullable', 'string', 'max:10', 'regex:/^(UN|NA)? ?[0-9]{4}$/i'],
            'classe_danger' => ['nullable', 'string', 'max:10', 'regex:/^[1-9]([.][1-6])?[A-Za-z]?$/'],
            'groupe_emballage' => ['nullable', Rule::in(['I', 'II', 'III'])],
            'poids_kg' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'volume_m3' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ];
    }

    /** Champs de transport transmis, mis en forme : « UN1203 », classe en majuscules. */
    private function transport(Request $request): array
    {
        $valeurs = $request->only(array_keys($this->reglesTransport()));
        if (filled($valeurs['numero_onu'] ?? null)) {
            $prefixe = str_starts_with(strtoupper(trim($valeurs['numero_onu'])), 'NA') ? 'NA' : 'UN';
            $valeurs['numero_onu'] = $prefixe.preg_replace('/[^0-9]/', '', $valeurs['numero_onu']);
        }
        if (filled($valeurs['classe_danger'] ?? null)) {
            $valeurs['classe_danger'] = strtoupper(trim($valeurs['classe_danger']));
        }

        return $valeurs;
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'numero' => ['nullable', 'string', 'max:50', $this->numeroNonDesactive(), TenantRule::unique('produits', 'numero')],
            'description' => 'required|string|max:255',
            'mesure' => 'sometimes|string|max:20',
            'unite_achat' => 'nullable|string|max:30',
            'qte_par_unite_achat' => 'nullable|integer|min:1',
            'type' => 'nullable|string|max:50',
            'secteur_id' => ['required', TenantRule::exists('secteurs')],
            'categorie' => 'nullable|string|max:100',
            'prix_unitaire' => 'nullable|numeric|min:0',
            ...$this->reglesTransport(),
        ]);

        // Générer le numéro automatiquement si non fourni
        $numero = $request->numero;
        if (empty($numero)) {
            $config = Configuration::pourEntite('produit', $request->attributes->get('tenant')->id);
            if ($config && $config->auto_increment) {
                $numero = $config->genererNumero();
                $config->incrementer();
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Le numéro est requis (pas de configuration auto-increment)',
                ], 422);
            }
        }

        $produit = ProduitTenant::create([
            'tenant_id' => auth()->user()->tenant_id,
            'numero' => $numero,
            'description' => $request->description,
            'mesure' => $request->mesure ?? 'UN',
            'unite_achat' => $request->unite_achat,
            'qte_par_unite_achat' => $request->qte_par_unite_achat ?? 1,
            'type' => $request->type,
            'secteur_id' => $request->secteur_id,
            'categorie' => $request->categorie,
            'prix_unitaire' => $request->prix_unitaire,
        ] + $this->transport($request));

        $produit->load('secteur');

        return response()->json([
            'success' => true,
            'message' => 'Produit créé avec succès',
            'produit' => $produit,
        ], 201);
    }

    public function show($id): JsonResponse
    {
        $produit = ProduitTenant::with('secteur')->findOrFail($id);

        return response()->json($produit);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $produit = ProduitTenant::findOrFail($id);

        $request->validate([
            'numero' => ['sometimes', 'string', 'max:50', $this->numeroNonDesactive((int) $id), TenantRule::unique('produits', 'numero', $id)],
            'description' => 'sometimes|string|max:255',
            'mesure' => 'sometimes|string|max:20',
            'unite_achat' => 'nullable|string|max:30',
            'qte_par_unite_achat' => 'nullable|integer|min:1',
            'type' => 'nullable|string|max:50',
            'secteur_id' => ['sometimes', TenantRule::exists('secteurs')],
            'categorie' => 'nullable|string|max:100',
            'prix_unitaire' => 'nullable|numeric|min:0',
            'actif' => 'sometimes|boolean',
            ...$this->reglesTransport(),
        ]);

        $produit->fill(array_merge(
            $request->only(['numero', 'description', 'mesure', 'unite_achat', 'qte_par_unite_achat', 'type', 'secteur_id', 'categorie', 'prix_unitaire', 'actif']),
            $this->transport($request),
        ));
        $produit->save();

        return response()->json([
            'success' => true,
            'message' => 'Produit modifié avec succès',
            'produit' => $produit,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $produit = ProduitTenant::findOrFail($id);
        $produit->actif = false;
        $produit->save();

        return response()->json([
            'success' => true,
            'message' => 'Produit désactivé avec succès',
        ]);
    }

    /**
     * Valide qu'un numéro de produit existe dans la base
     * Utilisé par l'app mobile pour vérifier un code-barres scanné
     */
    public function valider(Request $request): JsonResponse
    {
        $request->validate([
            'numero' => 'required|string',
        ]);

        $numero = $request->input('numero');
        $produit = ProduitTenant::where('numero', $numero)
            ->where('actif', true)
            ->first();

        if (! $produit) {
            return response()->json([
                'valide' => false,
                'message' => 'Produit introuvable',
            ], 404);
        }

        return response()->json([
            'valide' => true,
            'numero' => $produit->numero,
            'description' => $produit->description,
            'unite_mesure' => $produit->mesure,
            'type' => $produit->type,
        ]);
    }
}
