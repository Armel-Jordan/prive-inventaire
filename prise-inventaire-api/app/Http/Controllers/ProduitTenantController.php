<?php

namespace App\Http\Controllers;

use App\Models\Configuration;
use App\Models\PieceJointe;
use App\Models\ProduitTenant;
use App\Models\ProduitTraduction;
use App\Services\CodesBarresProduits;
use App\Services\LotsSeries;
use App\Support\TenantRule;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProduitTenantController extends Controller
{
    /**
     * Produits actifs (listes de choix, application mobile) ; ?statut=inactifs ou ?statut=tous pour retrouver les
     * produits désactivés, qui disparaissaient de l'écran sans moyen de les réactiver.
     */
    public function index(Request $request): JsonResponse
    {
        $statut = $request->query('statut', 'actifs');
        // Unités de vente : les lignes des documents proposent l'unité et son prix.
        // Annexes : les lignes des documents les ajoutent d'office ; composants : le produit est un ensemble.
        $produits = ProduitTenant::with(['secteur', 'codesBarres:id,produit_id,code,type,quantite,principal', 'unites.unite:id,code,libelle,decimales',
            'annexes:id,produit_id,lie_id,quantite', 'composants:id,produit_id,lie_id,quantite', 'fabricant:id,nom', 'traductions'])
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
                $echec(__('Le numéro « :numero » appartient au produit désactivé « :description » : réactivez-le (filtre « Désactivés » de la liste des produits).', ['numero' => $valeur, 'description' => $desactive->description]));
            }
        };
    }

    /**
     * Fiche enrichie (phase 2.4) : fabricant (référentiel) et référence chez lui, description longue, image principale
     * (une image jointe à la fiche), descriptions traduites.
     */
    private function reglesFiche(?ProduitTenant $produit = null): array
    {
        return [
            // Un fabricant du référentiel ; celui de la fiche reste accepté s'il a été supprimé depuis (la fiche se réenregistre).
            'fabricant_id' => ['nullable', 'integer', TenantRule::exists('fabricants')->where(fn ($q) => $q->whereNull('deleted_at')
                ->when($produit?->fabricant_id, fn ($q, $actuel) => $q->orWhere('id', $actuel)))],
            'reference_fabricant' => ['nullable', 'string', 'max:60'],
            'description_longue' => ['nullable', 'string', 'max:10000'],
            'image_id' => ['nullable', 'integer'],
            'traductions' => ['sometimes', 'array', 'max:5'],
            'traductions.*.langue' => ['required', Rule::in(ProduitTraduction::LANGUES), 'distinct'],
            'traductions.*.description' => ['nullable', 'string', 'max:255'],
            'traductions.*.description_longue' => ['nullable', 'string', 'max:10000'],
            'suivi' => ['nullable', Rule::in(ProduitTenant::SUIVIS)],
            'peremption' => ['sometimes', 'boolean'],
        ];
    }

    /** Valeurs de la fiche enrichie transmises ; l'image principale doit être une image jointe à ce produit. */
    private function fiche(Request $request, ?ProduitTenant $produit): array
    {
        $valeurs = $request->only(['fabricant_id', 'reference_fabricant', 'description_longue']);
        if ($request->has('image_id')) {
            $image = $request->input('image_id');
            if ($image !== null) {
                $jointe = $produit && PieceJointe::query()->whereKey($image)->where('entite', 'produit')->where('entite_id', $produit->id)
                    ->whereIn('type_mime', ProduitTenant::TYPES_IMAGE)->exists();
                if (! $jointe) {
                    throw ValidationException::withMessages(['image_id' => __('Choisissez une image jointe à cette fiche produit.')]);
                }
            }
            $valeurs['image_id'] = $image;
        }

        return $valeurs;
    }

    /** Descriptions traduites : une par langue ; sans texte, la traduction est retirée. */
    private function enregistrerTraductions(ProduitTenant $produit, Request $request): void
    {
        foreach ($request->input('traductions', []) as $traduction) {
            $texte = ['description' => trim((string) ($traduction['description'] ?? '')) ?: null, 'description_longue' => trim((string) ($traduction['description_longue'] ?? '')) ?: null];
            if ($texte['description'] === null && $texte['description_longue'] === null) {
                ProduitTraduction::query()->where('produit_id', $produit->id)->where('langue', $traduction['langue'])->delete();
            } else {
                ProduitTraduction::query()->updateOrCreate(['produit_id' => $produit->id, 'langue' => $traduction['langue']], $texte);
            }
        }
    }

    /**
     * Suivi des exemplaires (phase 2.8) : par lot ou par numéro de série, et date de péremption exigée pour un lot. Le
     * suivi ne change plus dès que des lots ou des numéros de série sont enregistrés pour le produit.
     */
    private function suivi(Request $request, ?ProduitTenant $produit): array
    {
        $valeurs = [];
        if ($request->has('suivi')) {
            $suivi = $request->input('suivi') ?: null;
            if ($produit && $suivi !== $produit->suivi && ! app(LotsSeries::class)->suiviModifiable($produit)) {
                throw ValidationException::withMessages(['suivi' => __('Des lots ou des numéros de série sont déjà enregistrés pour ce produit : son suivi ne peut plus changer.')]);
            }
            $valeurs['suivi'] = $suivi;
        }
        if ($request->has('peremption')) {
            $valeurs['peremption'] = $request->boolean('peremption');
        }

        return $valeurs;
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
            ...$this->reglesFiche(),
        ]);
        if ($request->filled('image_id')) {
            // Une image se joint à la fiche une fois le produit créé.
            throw ValidationException::withMessages(['image_id' => __('Choisissez une image jointe à cette fiche produit.')]);
        }

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
                    'message' => __('Le numéro est requis (pas de configuration auto-increment)'),
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
        ] + $this->transport($request) + $this->fiche($request, null) + $this->suivi($request, null));
        $this->enregistrerTraductions($produit, $request);

        $produit->load(['secteur', 'fabricant:id,nom', 'traductions']);

        return response()->json([
            'success' => true,
            'message' => __('Produit créé avec succès'),
            'produit' => $produit,
        ], 201);
    }

    public function show($id): JsonResponse
    {
        $produit = ProduitTenant::with(['secteur', 'fabricant:id,nom', 'traductions'])->findOrFail($id);

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
            ...$this->reglesFiche($produit),
        ]);

        $produit->fill(array_merge(
            $request->only(['numero', 'description', 'mesure', 'unite_achat', 'qte_par_unite_achat', 'type', 'secteur_id', 'categorie', 'prix_unitaire', 'actif']),
            $this->transport($request),
            $this->fiche($request, $produit),
            $this->suivi($request, $produit),
        ));
        $produit->save();
        $this->enregistrerTraductions($produit, $request);
        $produit->load(['fabricant:id,nom', 'traductions']);

        return response()->json([
            'success' => true,
            'message' => __('Produit modifié avec succès'),
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
            'message' => __('Produit désactivé avec succès'),
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

        // Le numéro d'un produit actif, sinon l'un de ses codes-barres (avec la quantité que compte une lecture).
        $lecture = app(CodesBarresProduits::class)->resoudre((string) $request->input('numero'));

        if (! $lecture) {
            return response()->json([
                'valide' => false,
                'message' => __('Produit introuvable'),
            ], 404);
        }
        $produit = $lecture['produit'];

        return response()->json([
            'valide' => true,
            'numero' => $produit->numero,
            'description' => $produit->description,
            'unite_mesure' => $produit->mesure,
            'type' => $produit->type,
            'code_lu' => $lecture['code'],
            'quantite_par_lecture' => $lecture['quantite'],
        ]);
    }
}
