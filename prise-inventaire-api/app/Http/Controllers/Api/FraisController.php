<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Frais;
use App\Services\FraisDocuments;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Frais additionnels et de livraison : réglage (configuration) et calcul des lignes de frais d'un document de vente.
 * Les règles de calcul sont dans App\Services\FraisDocuments.
 */
class FraisController extends Controller
{
    public function __construct(private readonly FraisDocuments $frais) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(Frais::with(['produit:id,numero,description', 'produits:id,numero,description'])
            ->when($request->filled('application'), fn ($q) => $q->where('application', $request->get('application')))
            ->when($request->boolean('actifs'), fn ($q) => $q->where('actif', true))
            ->orderBy('libelle')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $this->valider($request);

        return response()->json($this->enregistrer(new Frais, $donnees), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $frais = Frais::findOrFail($id);
        $donnees = $this->valider($request);

        return response()->json($this->enregistrer($frais, $donnees));
    }

    /** Suppression : les clients qui l'avaient comme frais de livraison n'en ont plus. */
    public function destroy(int $id): JsonResponse
    {
        $frais = Frais::findOrFail($id);
        DB::transaction(function () use ($frais) {
            Client::where('frais_livraison_id', $frais->id)->update(['frais_livraison_id' => null]);
            $frais->delete();
        });

        return response()->json(['message' => __('Frais supprimé.')]);
    }

    /** Lignes de frais à proposer pour un document en cours de saisie. */
    public function calculer(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'document' => ['required', Rule::in(array_keys(FraisDocuments::DOCUMENTS))],
            'client_id' => ['nullable', 'integer', TenantRule::exists('clients')],
            'taux_change' => 'nullable|numeric|gt:0',
            'lignes' => 'present|array',
            'lignes.*.produit_id' => 'nullable|integer',
            'lignes.*.quantite' => 'nullable|numeric',
            'lignes.*.prix_unitaire_ht' => 'nullable|numeric',
            'lignes.*.remise_ligne' => 'nullable|numeric',
        ]);
        $client = empty($donnees['client_id']) ? null : Client::find($donnees['client_id']);

        return response()->json($this->frais->calculer($donnees['document'], $client, $donnees['lignes'], (float) ($donnees['taux_change'] ?? 1)));
    }

    private function valider(Request $request): array
    {
        $donnees = $request->validate([
            'libelle' => 'required|string|max:100',
            'produit_id' => ['required', 'integer', TenantRule::exists('produits')->whereNull('deleted_at')],
            'mode' => ['required', Rule::in(Frais::MODES)],
            'valeur' => ['required', 'numeric', 'gt:0', ...($request->input('mode') === Frais::POURCENTAGE ? ['max:100'] : ['max:99999999'])],
            'application' => ['required', Rule::in(Frais::APPLICATIONS)],
            'repetition' => ['nullable', Rule::in(Frais::REPETITIONS)],
            'sur_devis' => 'boolean',
            'sur_commandes' => 'boolean',
            'sur_factures' => 'boolean',
            'actif' => 'boolean',
            'matieres_dangereuses' => 'boolean',
            // Sans produits choisis, un frais « par produit » doit viser les matières dangereuses.
            'produits' => ['nullable', 'array', Rule::requiredIf($request->input('application') === Frais::PRODUITS && ! $request->boolean('matieres_dangereuses'))],
            'produits.*' => ['integer', 'distinct', TenantRule::exists('produits')->whereNull('deleted_at')],
        ], [
            'produits.required' => __('Choisissez les produits qui déclenchent ce frais (ou appliquez-le aux matières dangereuses).'),
            'valeur.max' => __('Un pourcentage ne dépasse pas 100.'),
        ]);
        if (! ($donnees['sur_devis'] ?? true) && ! ($donnees['sur_commandes'] ?? true) && ! ($donnees['sur_factures'] ?? true)) {
            throw ValidationException::withMessages(['sur_factures' => __('Choisissez au moins un type de document.')]);
        }
        if (in_array((int) $donnees['produit_id'], array_map('intval', $donnees['produits'] ?? []), true)) {
            throw ValidationException::withMessages(['produits' => __('Le produit du frais ne peut pas le déclencher lui-même.')]);
        }

        return $donnees;
    }

    private function enregistrer(Frais $frais, array $donnees): Frais
    {
        return DB::transaction(function () use ($frais, $donnees) {
            $frais->fill([
                'libelle' => $donnees['libelle'],
                'produit_id' => $donnees['produit_id'],
                'mode' => $donnees['mode'],
                'valeur' => $donnees['valeur'],
                'application' => $donnees['application'],
                // Un pourcentage porte sur le montant des lignes concernées : une seule ligne de frais.
                'repetition' => $donnees['mode'] === Frais::POURCENTAGE ? 'document' : ($donnees['repetition'] ?? 'document'),
                'sur_devis' => $donnees['sur_devis'] ?? true,
                'sur_commandes' => $donnees['sur_commandes'] ?? true,
                'sur_factures' => $donnees['sur_factures'] ?? true,
                'actif' => $donnees['actif'] ?? true,
                'matieres_dangereuses' => $donnees['application'] === Frais::PRODUITS && ($donnees['matieres_dangereuses'] ?? false),
            ])->save();
            $frais->produits()->sync($donnees['application'] === Frais::PRODUITS ? ($donnees['produits'] ?? []) : []);
            // Un frais qui n'est plus de livraison quitte les fiches clients.
            if ($donnees['application'] !== Frais::LIVRAISON) {
                Client::where('frais_livraison_id', $frais->id)->update(['frais_livraison_id' => null]);
            }

            return $frais->load(['produit:id,numero,description', 'produits:id,numero,description']);
        });
    }
}
