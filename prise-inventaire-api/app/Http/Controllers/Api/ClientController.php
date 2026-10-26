<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Configuration;
use App\Models\Devis;
use App\Models\Facture;
use App\Services\ClientsDePassage;
use App\Services\DevisesVente;
use App\Services\EntetesVente;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $query = Client::where('tenant_id', $tenantId);

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('raison_sociale', 'like', "%{$search}%")
                    ->orWhere('ville', 'like', "%{$search}%");
            });
        }

        if ($request->has('actif')) {
            $query->where('actif', $request->boolean('actif'));
        }

        // Fiche client : filtres facultatifs (catégorie, favoris de l'utilisateur), catégorie et favori affichés.
        $utilisateur = $request->user()->id;
        $query->when($request->filled('categorie_id'), fn ($q) => $q->where('categorie_id', $request->integer('categorie_id')))
            ->when($request->boolean('favoris'), fn ($q) => $q->whereHas('favoris', fn ($f) => $f->where('user_id', $utilisateur)))
            // Clients d'un représentant (phase 1.11a).
            ->when($request->filled('representant_id'), fn ($q) => $q->where('representant_id', $request->integer('representant_id')))
            // Clients d'un transporteur (phase 1.11b).
            ->when($request->filled('transporteur_id'), fn ($q) => $q->where('transporteur_id', $request->integer('transporteur_id')))
            ->with(['categorie:id,nom', 'representant:id,nom', 'transporteur:id,nom'])
            ->withExists(['favoris as favori' => fn ($f) => $f->where('user_id', $utilisateur)]);

        $clients = $query->orderBy('raison_sociale')->paginate($request->get('per_page', 20));

        return response()->json($clients);
    }

    public function actifs(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $clients = Client::where('tenant_id', $tenantId)->where('actif', true)->orderBy('raison_sociale')->get();

        return response()->json($clients);
    }

    /** Fiche du client de passage de l'entreprise (ventes au comptoir), créée à sa première utilisation. */
    public function dePassage(ClientsDePassage $passage): JsonResponse
    {
        return response()->json($passage->client());
    }

    public function show(int $id): JsonResponse
    {
        $client = Client::with('conditionsPaiement')->findOrFail($id);

        return response()->json($client);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'raison_sociale' => 'required|string|max:255',
            'adresse_facturation' => 'required|string',
            'adresse_livraison' => 'nullable|string',
            'ville' => 'required|string|max:100',
            'code_postal' => 'required|string|max:10',
            'telephone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'contact_nom' => 'nullable|string|max:100',
            'contact_telephone' => 'nullable|string|max:20',
            'encours_max' => 'nullable|numeric|min:0',
            'taux_remise_global' => 'nullable|numeric|min:0|max:100',
            // Condition de paiement du référentiel : reprise par les factures du client.
            'condition_paiement_id' => ['nullable', 'integer', TenantRule::exists('conditions_paiement')->whereNull('deleted_at')],
            // Devise du client (code ISO) : reprise par ses documents de vente.
            'devise' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            // Frais de livraison (frais « livraison ») proposé sur ses documents de vente.
            'frais_livraison_id' => ['nullable', 'integer', TenantRule::exists('frais')->where('application', 'livraison')->whereNull('deleted_at')],
            // Route de livraison et rang de passage (ordre des arrêts des tournées).
            'itineraire_id' => ['nullable', 'integer', TenantRule::exists('itineraires')->whereNull('deleted_at')],
            'sequence_livraison' => ['nullable', 'integer', 'min:0', 'max:999999'],
            // Représentant commercial et transporteur : repris par ses documents de vente.
            ...EntetesVente::regles(),
        ]);
        $validated['devise'] = $this->devise($validated['devise'] ?? null);

        $tenantId = $request->attributes->get('tenant')->id;
        $config = Configuration::pourEntite('client', $tenantId);
        if ($config && $config->auto_increment) {
            $validated['code'] = $config->genererNumero();
            $config->incrementer();
        } else {
            return response()->json(['success' => false, 'message' => __('Numéro requis')], 422);
        }
        $validated['actif'] = true;
        $validated['tenant_id'] = $tenantId;

        $client = Client::create($validated);

        return response()->json($client, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $client = Client::findOrFail($id);

        $validated = $request->validate([
            'raison_sociale' => 'sometimes|string|max:255',
            'adresse_facturation' => 'sometimes|string',
            'adresse_livraison' => 'nullable|string',
            'ville' => 'sometimes|string|max:100',
            'code_postal' => 'sometimes|string|max:10',
            'telephone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'contact_nom' => 'nullable|string|max:100',
            'contact_telephone' => 'nullable|string|max:20',
            'encours_max' => 'nullable|numeric|min:0',
            'taux_remise_global' => 'nullable|numeric|min:0|max:100',
            'condition_paiement_id' => ['nullable', 'integer', TenantRule::exists('conditions_paiement')->whereNull('deleted_at')],
            'devise' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'frais_livraison_id' => ['nullable', 'integer', TenantRule::exists('frais')->where('application', 'livraison')->whereNull('deleted_at')],
            'itineraire_id' => ['nullable', 'integer', TenantRule::exists('itineraires')->whereNull('deleted_at')],
            'sequence_livraison' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'actif' => 'sometimes|boolean',
            ...EntetesVente::regles($client),
        ]);
        if (array_key_exists('devise', $validated)) {
            $validated['devise'] = $this->devise($validated['devise']);
        }

        $client->update($validated);

        return response()->json($client);
    }

    /** Devise d'un client : en majuscules ; celle de l'entreprise s'écrit null. */
    private function devise(?string $devise): ?string
    {
        $code = strtoupper(trim((string) $devise));

        return $code === '' || $code === app(DevisesVente::class)->reference() ? null : $code;
    }

    public function destroy(int $id): JsonResponse
    {
        $client = Client::findOrFail($id);

        // Un client qui a des documents reste : ses factures et devis perdaient leur destinataire.
        // Pour ne plus le proposer, on le rend inactif.
        if ($client->commandes()->exists()
            || Facture::where('client_id', $client->id)->exists()
            || Devis::where('client_id', $client->id)->exists()) {
            return response()->json(['message' => __('Impossible de supprimer un client qui a des commandes, factures ou devis : rendez-le plutôt inactif.')], 422);
        }

        $client->delete();

        return response()->json(['message' => __('Client supprimé')]);
    }

    public function getConditionsPaiement(int $id): JsonResponse
    {
        $client = Client::findOrFail($id);

        return response()->json($client->conditionsPaiement);
    }

    public function setConditionsPaiement(Request $request, int $id): JsonResponse
    {
        $client = Client::findOrFail($id);

        $validated = $request->validate([
            'conditions' => 'required|array|min:1',
            'conditions.*.libelle' => 'required|string|max:100',
            'conditions.*.nb_jours' => 'required|integer|min:0',
            'conditions.*.pourcentage' => 'required|numeric|min:0|max:100',
        ]);

        $totalPourcentage = collect($validated['conditions'])->sum('pourcentage');
        if (abs($totalPourcentage - 100) > 0.01) {
            return response()->json(['message' => __('Le total des pourcentages doit être égal à 100%')], 422);
        }

        DB::transaction(function () use ($client, $validated) {
            $client->conditionsPaiement()->delete();

            foreach ($validated['conditions'] as $index => $condition) {
                $client->conditionsPaiement()->create([
                    'libelle' => $condition['libelle'],
                    'nb_jours' => $condition['nb_jours'],
                    'pourcentage' => $condition['pourcentage'],
                    'ordre' => $index + 1,
                ]);
            }
        });

        return response()->json($client->conditionsPaiement()->get());
    }
}
