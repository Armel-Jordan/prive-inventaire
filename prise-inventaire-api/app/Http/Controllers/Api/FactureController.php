<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BonLivraison;
use App\Models\BonLivraisonLigne;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\Configuration;
use App\Models\Facture;
use App\Models\FactureEcheance;
use App\Models\FactureLigne;
use App\Models\FacturePaiement;
use App\Services\Acomptes;
use App\Services\ConditionsPaiement;
use App\Services\DevisesVente;
use App\Services\FacturesVente;
use App\Services\TaxesDocument;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FactureController extends Controller
{
    public function __construct(
        private readonly TaxesDocument $taxes,
        private readonly ConditionsPaiement $conditions,
        private readonly FacturesVente $factures,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        // Le bon de livraison en cours permet à la liste de ne plus proposer d'en créer un deuxième.
        $query = Facture::with(['client', 'commande', 'bonLivraison:id,facture_id,numero,statut'])->where('tenant_id', $tenantId);

        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }
        // Plusieurs statuts (« emise,payee ») : factures à créditer, par exemple.
        if ($request->filled('statuts')) {
            $query->whereIn('statut', explode(',', (string) $request->statuts));
        }
        // Numéro de facture, nom ou code du client.
        if ($request->filled('recherche')) {
            $motif = '%'.addcslashes(trim((string) $request->recherche), '%_\\').'%';
            $query->where(fn ($q) => $q->where('numero', 'like', $motif)
                ->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $motif)->orWhere('code', 'like', $motif)));
        }
        if ($request->has('client_id')) {
            $query->where('client_id', $request->client_id);
        }
        if ($request->has('date_debut')) {
            $query->whereDate('date_facture', '>=', $request->date_debut);
        }
        if ($request->has('date_fin')) {
            $query->whereDate('date_facture', '<=', $request->date_fin);
        }

        $factures = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 20));

        return response()->json($factures);
    }

    public function show(int $id): JsonResponse
    {
        // Produit de chaque ligne (même retiré du catalogue) : le détail affichait « Produit #12 ».
        // Notes de crédit établies sur la facture et crédits imputés (de ses notes ou d'autres notes du client),
        // retours de sa marchandise.
        $facture = Facture::with([
            'client', 'commande', 'lignes', 'echeances', 'paiements', 'bonLivraison', 'conditionPaiement:id,code,libelle,delai_jours,fin_de_mois,escompte_pourcentage,escompte_jours',
            'lignes.produit' => fn ($q) => $q->withTrashed()->select('id', 'numero', 'description', 'mesure'),
            'avoirs' => fn ($q) => $q->select('id', 'facture_id', 'numero', 'statut', 'date_avoir', 'montant_ttc', 'montant_impute', 'montant_rembourse')->orderBy('id'),
            'paiements.acompte:id,date_acompte,reference',
            'imputations.avoir:id,numero',
            'retours' => fn ($q) => $q->select('id', 'facture_id', 'numero', 'statut', 'date_retour')->orderBy('id'),
        ])->findOrFail($id);
        // Détail par taxe (vide si les lignes utilisent le calcul au taux unique).
        $facture->setAttribute('recapitulatif_taxes', $this->taxes->recapitulatif('facture', $facture->lignes->pluck('id')));

        return response()->json($facture);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', TenantRule::exists('clients')],
            'date_facture' => 'required|date',
            'date_echeance' => 'nullable|date',
            // Condition de paiement (référentiel) ; par défaut, celle du client, sinon celle de l'entreprise.
            'condition_paiement_id' => ['nullable', 'integer', TenantRule::exists('conditions_paiement')->whereNull('deleted_at')],
            ...DevisesVente::regles(),
            'notes' => 'nullable|string',
            'lignes' => 'required|array|min:1',
            // Produit de l'entreprise : un id quelconque (0, ou celui d'une autre entreprise) était accepté.
            'lignes.*.produit_id' => ['required', 'integer', TenantRule::exists('produits')],
            'lignes.*.quantite' => 'required|integer|min:1',
            'lignes.*.prix_unitaire_ht' => 'required|numeric|min:0',
            'lignes.*.taux_tva' => 'nullable|numeric|min:0|max:100',
            'lignes.*.groupe_taxe_id' => ['nullable', 'integer', TenantRule::exists('groupes_taxes')->whereNull('deleted_at')],
            'lignes.*.remise_ligne' => 'nullable|numeric|min:0|max:100',
        ]);

        $tenantId = $request->attributes->get('tenant')->id;
        $config = Configuration::pourEntite('facture', $tenantId);
        if (! $config || ! $config->auto_increment) {
            return response()->json(['success' => false, 'message' => 'Numéro requis'], 422);
        }
        // Condition de paiement, devise, taxes par ligne : voir App\Services\FacturesVente.
        $facture = $this->factures->creer($validated, $tenantId);

        return response()->json($facture->load(['client', 'lignes']), 201);
    }

    public function creerDepuisCommande(Request $request, int $commandeId): JsonResponse
    {
        $commande = ComClientEntete::with(['client', 'lignes'])->findOrFail($commandeId);

        if ($commande->statut !== 'acceptee') {
            return response()->json(['message' => 'La commande doit être acceptée pour créer une facture'], 422);
        }

        if ($commande->facture) {
            return response()->json(['message' => 'Une facture existe déjà pour cette commande'], 422);
        }

        $tenantId = $request->attributes->get('tenant')->id;
        $configFacture = Configuration::pourEntite('facture', $tenantId);
        if (! $configFacture || ! $configFacture->auto_increment) {
            return response()->json(['success' => false, 'message' => 'Numéro requis'], 422);
        }
        $condition = $this->conditions->pourFacture(null, $commande->client);
        $facture = DB::transaction(function () use ($commande, $tenantId, $condition) {
            $numeroFacture = Configuration::consommerNumero('facture', $tenantId);

            $facture = Facture::create([
                'tenant_id' => $tenantId,
                'numero' => $numeroFacture,
                'commande_id' => $commande->id,
                'client_id' => $commande->client_id,
                // La facture reste dans la devise de la commande, à son taux.
                'devise' => $commande->devise,
                'taux_change' => $commande->taux_change ?? 1,
                'date_facture' => now(),
                'condition_paiement_id' => $condition?->id,
                'statut' => 'brouillon',
                'montant_ht' => $commande->montant_ht,
                'montant_tva' => $commande->montant_tva,
                'montant_ttc' => $commande->montant_ttc,
                'reste_a_payer' => $commande->montant_ttc,
            ]);

            foreach ($commande->lignes as $ligne) {
                $ligneFacture = FactureLigne::create([
                    'facture_id' => $facture->id,
                    'produit_id' => $ligne->produit_id,
                    'quantite' => $ligne->quantite,
                    'prix_unitaire_ht' => $ligne->prix_unitaire_ht,
                    'taux_tva' => $ligne->taux_tva,
                    'groupe_taxe_id' => $ligne->groupe_taxe_id,
                    'remise_ligne' => $ligne->remise_ligne,
                    'montant_ht' => $ligne->montant_ht,
                    'montant_tva' => $ligne->montant_ttc - $ligne->montant_ht,
                    'montant_ttc' => $ligne->montant_ttc,
                ]);
                // La facture reprend le détail des taxes calculé sur la commande.
                $this->taxes->copier('commande_client', $ligne->id, 'facture', $ligneFacture->id);
            }

            $conditions = $commande->client->conditionsPaiement;
            if ($conditions->isEmpty()) {
                // Échéance de la condition de paiement, sinon 30 jours.
                $echeance = $condition ? $this->conditions->echeance($condition, now()) : now()->addDays(30);
                FactureEcheance::create([
                    'facture_id' => $facture->id,
                    'date_echeance' => $echeance,
                    'montant' => $facture->montant_ttc,
                    'ordre' => 1,
                ]);
                $facture->date_echeance = $echeance;
            } else {
                foreach ($conditions as $condition) {
                    FactureEcheance::create([
                        'facture_id' => $facture->id,
                        'date_echeance' => now()->addDays($condition->nb_jours),
                        'montant' => $facture->montant_ttc * $condition->pourcentage / 100,
                        'ordre' => $condition->ordre,
                    ]);
                }
                $facture->date_echeance = now()->addDays($conditions->first()->nb_jours);
            }
            $facture->save();

            $commande->statut = 'facturee';
            $commande->save();

            return $facture;
        });

        return response()->json($facture->load(['lignes', 'echeances']), 201);
    }

    public function emettre(int $id): JsonResponse
    {
        $facture = Facture::findOrFail($id);

        if ($facture->statut !== 'brouillon') {
            return response()->json(['message' => 'Cette facture ne peut pas être émise'], 422);
        }

        $facture->statut = 'emise';
        $facture->save();
        $facture->client->updateEncours();
        // Acomptes réservés à la commande de la facture : appliqués d'eux-mêmes à son paiement.
        app(Acomptes::class)->appliquerReserves($facture, auth()->id());

        return response()->json($facture->fresh());
    }

    public function enregistrerPaiement(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'montant' => 'required|numeric|min:0.01',
            'date_paiement' => 'required|date',
            'mode_paiement' => 'required|in:especes,cheque,virement,carte,autre',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            // Échéance de cette facture uniquement.
            'echeance_id' => ['nullable', Rule::exists('facture_echeances', 'id')->where('facture_id', $id)],
            // Escompte pour paiement rapide (condition de paiement) : le paiement règle alors tout le reste.
            'escompte' => 'sometimes|boolean',
        ]);

        try {
            $facture = DB::transaction(function () use ($id, $validated, $request) {
                // Verrou sur la facture : sérialise les paiements concurrents et
                // évite le double encaissement basé sur un reste_a_payer périmé.
                $facture = Facture::where('id', $id)->lockForUpdate()->firstOrFail();

                if (! in_array($facture->statut, ['emise', 'partiellement_payee'])) {
                    throw new \DomainException('Cette facture ne peut pas recevoir de paiement');
                }

                if ($validated['montant'] > $facture->reste_a_payer) {
                    throw new \DomainException('Le montant dépasse le reste à payer');
                }
                $escompte = 0.0;
                if ($validated['escompte'] ?? false) {
                    $propose = $this->conditions->escompte($facture, Carbon::parse($validated['date_paiement'])->toDateString());
                    if (! $propose || ! $propose['applicable']) {
                        throw new \DomainException($propose
                            ? 'Escompte impossible : le paiement doit être fait au plus tard le '.Carbon::parse($propose['date_limite'])->format('d/m/Y').'.'
                            : 'Aucun escompte possible sur cette facture.');
                    }
                    if (abs((float) $validated['montant'] - $propose['a_payer']) > 0.01) {
                        throw new \DomainException('Avec l\'escompte, le paiement règle tout le reste : '.number_format($propose['a_payer'], 2, ',', ' ').'.');
                    }
                    $escompte = $propose['escompte'];
                }

                FacturePaiement::create([
                    'facture_id' => $facture->id,
                    'echeance_id' => $validated['echeance_id'] ?? null,
                    'date_paiement' => $validated['date_paiement'],
                    'montant' => $validated['montant'],
                    'escompte' => $escompte,
                    'mode_paiement' => $validated['mode_paiement'],
                    'reference' => $validated['reference'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'enregistre_par' => $request->user()?->id,
                ]);

                // Recalcul autoritatif : montant_paye, reste_a_payer, statut, encours client.
                $facture->updateMontantPaye();

                return $facture;
            });
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($facture->fresh(['paiements']));
    }

    /** Escompte possible pour un paiement de la facture à une date (aujourd'hui par défaut). */
    public function escompte(Request $request, int $id): JsonResponse
    {
        $date = Carbon::parse($request->validate(['date' => 'nullable|date'])['date'] ?? today())->toDateString();

        return response()->json($this->conditions->escompte(Facture::findOrFail($id), $date) ?? ['applicable' => false]);
    }

    public function creerBonLivraison(Request $request, int $id): JsonResponse
    {
        $facture = Facture::with('lignes')->findOrFail($id);

        // Un bon annulé ne compte pas : la facture doit pouvoir être livrée avec un nouveau bon.
        if (BonLivraison::where('facture_id', $facture->id)->where('statut', '!=', 'annule')->exists()) {
            return response()->json(['message' => 'Un bon de livraison existe déjà'], 422);
        }

        if (! in_array($facture->statut, ['emise', 'partiellement_payee', 'payee'])) {
            return response()->json(['message' => 'La facture doit être émise'], 422);
        }

        $tenantId = $request->attributes->get('tenant')->id;
        $configBon = Configuration::pourEntite('bon_livraison', $tenantId);
        if (! $configBon || ! $configBon->auto_increment) {
            return response()->json(['success' => false, 'message' => 'Numéro requis'], 422);
        }
        $bon = DB::transaction(function () use ($facture, $tenantId) {
            $numeroBon = Configuration::consommerNumero('bon_livraison', $tenantId);

            $bon = BonLivraison::create([
                'tenant_id' => $tenantId,
                'numero' => $numeroBon,
                'facture_id' => $facture->id,
                'mode_livraison' => 'entreprise',
                'statut' => 'cree',
            ]);

            foreach ($facture->lignes as $ligne) {
                BonLivraisonLigne::create([
                    'bon_id' => $bon->id,
                    'produit_id' => $ligne->produit_id,
                    'quantite_a_livrer' => $ligne->quantite,
                ]);
            }

            return $bon;
        });

        return response()->json($bon->load('lignes'), 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $facture = Facture::findOrFail($id);

        if ($facture->statut !== 'brouillon') {
            return response()->json(['message' => 'Seules les factures en brouillon peuvent être supprimées.'], 422);
        }

        $facture->delete();

        return response()->json(['message' => 'Facture supprimée.']);
    }
}
