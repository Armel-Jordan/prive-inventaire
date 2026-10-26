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
use App\Services\Caisses;
use App\Services\CartesCadeaux;
use App\Services\ClientsDePassage;
use App\Services\ConditionsPaiement;
use App\Services\DevisesVente;
use App\Services\EntetesVente;
use App\Services\FacturesVente;
use App\Services\StatutsPersonnalises;
use App\Services\TaxesDocument;
use App\Services\UnitesProduits;
use App\Support\DateDuJour;
use App\Support\NumeroCommandeClient;
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
        private readonly Caisses $caisses,
        private readonly CartesCadeaux $cartes,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        // Le bon de livraison en cours permet à la liste de ne plus proposer d'en créer un deuxième.
        $query = Facture::with(['client', 'commande', 'bonLivraison:id,facture_id,numero,statut', 'representant:id,nom', 'transporteur:id,nom', StatutsPersonnalises::COLONNES])->where('tenant_id', $tenantId);

        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }
        // Plusieurs statuts (« emise,payee ») : factures à créditer, par exemple.
        if ($request->filled('statuts')) {
            $query->whereIn('statut', explode(',', (string) $request->statuts));
        }
        // Numéro de facture, nom ou code du client, nom noté pour un client de passage.
        if ($request->filled('recherche')) {
            $motif = '%'.addcslashes(trim((string) $request->recherche), '%_\\').'%';
            $query->where(fn ($q) => $q->where('numero', 'like', $motif)->orWhere('passage_nom', 'like', $motif)->orWhere('numero_commande_client', 'like', $motif)
                ->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $motif)->orWhere('code', 'like', $motif)));
        }
        if ($request->has('client_id')) {
            $query->where('client_id', $request->client_id);
        }
        if ($request->filled('representant_id')) {
            $query->where('representant_id', $request->integer('representant_id'));
        }
        if ($request->filled('transporteur_id')) {
            $query->where('transporteur_id', $request->integer('transporteur_id'));
        }
        if ($request->filled('statut_personnalise_id')) {
            $query->where('statut_personnalise_id', $request->integer('statut_personnalise_id'));
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
            'representant:id,nom',
            'transporteur:id,nom,url_suivi',
            StatutsPersonnalises::COLONNES,
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
        $validated = $request->validate($this->regles());
        $validated['lignes'] = app(UnitesProduits::class)->completerLignes($validated['lignes']);

        $tenantId = $request->attributes->get('tenant')->id;
        $config = Configuration::pourEntite('facture', $tenantId);
        if (! $config || ! $config->auto_increment) {
            return response()->json(['success' => false, 'message' => __('Numéro requis')], 422);
        }
        // Condition de paiement, devise, taxes par ligne : voir App\Services\FacturesVente.
        $facture = $this->factures->creer($validated, $tenantId);

        return response()->json($facture->load(['client', 'lignes']), 201);
    }

    /**
     * Modifie une facture brouillon : en-tête et lignes (toutes renvoyées, comme à la création), taxes et totaux
     * recalculés. Une facture créée par un abonnement, un contrat de location, un reliquat ou une consignation se
     * corrige à sa source : ses lignes y sont rattachées.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $facture = Facture::findOrFail($id);
        if ($facture->statut !== 'brouillon') {
            return response()->json(['message' => __('Seules les factures en brouillon peuvent être modifiées.')], 422);
        }
        $source = $this->sourceRattachee($facture);
        if ($source !== null) {
            return response()->json(['message' => __('Cette facture vient :source : corrigez-la à sa source, ou supprimez-la et refaites-la.', ['source' => $source])], 422);
        }

        $validated = $request->validate($this->regles($facture));
        if ($facture->commande_id && (int) $validated['client_id'] !== (int) $facture->client_id) {
            return response()->json(['message' => __("Le client d'une facture tirée d'une commande ne se change pas.")], 422);
        }
        $validated['lignes'] = app(UnitesProduits::class)->completerLignes($validated['lignes']);

        return response()->json($this->factures->modifier($facture, $validated)->load(['client', 'lignes']));
    }

    /** Règles de saisie d'une facture (création ou modification d'un brouillon). */
    private function regles(?Facture $facture = null): array
    {
        return [
            'client_id' => ['required', TenantRule::exists('clients')],
            'date_facture' => 'required|date',
            'date_echeance' => 'nullable|date',
            // Condition de paiement (référentiel) ; par défaut, celle du client, sinon celle de l'entreprise.
            'condition_paiement_id' => ['nullable', 'integer', TenantRule::exists('conditions_paiement')->whereNull('deleted_at')],
            ...DevisesVente::regles(),
            'notes' => 'nullable|string',
            // Numéro de suivi de l'envoi (connaissement), repris par le bon de livraison.
            'numero_suivi' => 'nullable|string|max:60',
            'lignes' => 'required|array|min:1',
            // Produit de l'entreprise : un id quelconque (0, ou celui d'une autre entreprise) était accepté.
            'lignes.*.produit_id' => ['required', 'integer', TenantRule::exists('produits')],
            'lignes.*.quantite' => 'required|integer|min:1',
            'lignes.*.prix_unitaire_ht' => 'required|numeric|min:0',
            'lignes.*.taux_tva' => 'nullable|numeric|min:0|max:100',
            'lignes.*.groupe_taxe_id' => ['nullable', 'integer', TenantRule::exists('groupes_taxes')->whereNull('deleted_at')],
            'lignes.*.remise_ligne' => 'nullable|numeric|min:0|max:100',
            // Unité de vente du produit (la caisse de 12…) ; vide : son unité de stock.
            'lignes.*.unite' => 'nullable|string|max:20',
            ...ClientsDePassage::regles(),
            // Représentant et transporteur : par défaut, ceux du client ; ceux de la facture restent acceptés s'ils ont été retirés.
            ...EntetesVente::regles($facture),
            ...NumeroCommandeClient::regles($facture),
        ];
    }

    /** Document à l'origine d'une facture brouillon dont les lignes y sont rattachées (texte du message), sinon null. */
    private function sourceRattachee(Facture $facture): ?string
    {
        return match (true) {
            DB::table('abonnement_echeances')->where('facture_id', $facture->id)->exists() => __("d'un abonnement"),
            DB::table('contrat_location_lignes')->where('facture_id', $facture->id)->exists() => __("d'un contrat de location"),
            DB::table('reliquats_clients')->where('facture_id', $facture->id)->exists() => __('de reliquats clients'),
            DB::table('consignation_mouvements')->where('facture_id', $facture->id)->exists() => __("d'une consignation"),
            default => null,
        };
    }

    public function creerDepuisCommande(Request $request, int $commandeId): JsonResponse
    {
        $commande = ComClientEntete::with(['client', 'lignes'])->findOrFail($commandeId);

        if ($commande->statut !== 'acceptee') {
            return response()->json(['message' => __('La commande doit être acceptée pour créer une facture')], 422);
        }

        if ($commande->facture) {
            return response()->json(['message' => __('Une facture existe déjà pour cette commande')], 422);
        }

        $tenantId = $request->attributes->get('tenant')->id;
        $configFacture = Configuration::pourEntite('facture', $tenantId);
        if (! $configFacture || ! $configFacture->auto_increment) {
            return response()->json(['success' => false, 'message' => __('Numéro requis')], 422);
        }
        $condition = $this->conditions->pourFacture(null, $commande->client);
        // Date de la facture : celle de l'usager (envoyée par l'écran), sinon le jour de l'entreprise.
        $date = DateDuJour::demandee($request);
        // Représentant de la commande, avec son taux de commission du jour.
        $entete = app(EntetesVente::class)->copier($commande);
        $entete['taux_commission'] = app(EntetesVente::class)->commission($entete['representant_id']);
        $facture = DB::transaction(function () use ($commande, $tenantId, $condition, $entete, $date) {
            // Numéro de la séquence, ou provisoire si l'entreprise numérote à l'émission.
            $numeroFacture = $this->factures->numeroBrouillon($tenantId);

            $facture = Facture::create(app(ClientsDePassage::class)->copier($commande) + $entete + [
                'tenant_id' => $tenantId,
                'numero' => $numeroFacture,
                'commande_id' => $commande->id,
                'client_id' => $commande->client_id,
                // La facture reste dans la devise de la commande, à son taux.
                'devise' => $commande->devise,
                'taux_change' => $commande->taux_change ?? 1,
                'date_facture' => $date,
                'condition_paiement_id' => $condition?->id,
                'numero_suivi' => $commande->numero_suivi,
                'numero_commande_client' => $commande->numero_commande_client,
                'statut' => 'brouillon',
                'montant_ht' => $commande->montant_ht,
                'montant_tva' => $commande->montant_tva,
                'montant_ttc' => $commande->montant_ttc,
                'reste_a_payer' => $commande->montant_ttc,
            ]);
            $this->factures->numeroterBrouillon($facture);

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
                    'unite' => $ligne->unite,
                    'facteur' => $ligne->facteur,
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

    public function emettre(int $id, FacturesVente $factures): JsonResponse
    {
        $facture = Facture::findOrFail($id);

        if ($facture->statut !== 'brouillon') {
            return response()->json(['message' => __('Cette facture ne peut pas être émise')], 422);
        }

        $factures->emettre($facture, auth()->id());

        return response()->json($facture->fresh());
    }

    public function enregistrerPaiement(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'montant' => 'required|numeric|min:0.01',
            'date_paiement' => 'required|date',
            // « carte_cadeau » : payé avec une carte cadeau (enregistré en mode « autre », rattaché à la carte).
            'mode_paiement' => 'required|in:especes,cheque,virement,carte,autre,carte_cadeau',
            'carte_cadeau' => 'nullable|required_if:mode_paiement,carte_cadeau|string|max:30',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            // Échéance de cette facture uniquement.
            'echeance_id' => ['nullable', Rule::exists('facture_echeances', 'id')->where('facture_id', $id)],
            // Escompte pour paiement rapide (condition de paiement) : le paiement règle alors tout le reste.
            'escompte' => 'sometimes|boolean',
            // Caisse du poste : le paiement entre dans sa session ouverte (refusé si elle est fermée).
            'caisse_id' => 'nullable|integer',
        ]);

        try {
            $facture = DB::transaction(function () use ($id, $validated, $request) {
                // Verrou sur la facture : sérialise les paiements concurrents et
                // évite le double encaissement basé sur un reste_a_payer périmé.
                $facture = Facture::where('id', $id)->lockForUpdate()->firstOrFail();

                if (! in_array($facture->statut, ['emise', 'partiellement_payee'])) {
                    throw new \DomainException(__('Cette facture ne peut pas recevoir de paiement'));
                }

                if ($validated['montant'] > $facture->reste_a_payer) {
                    throw new \DomainException(__('Le montant dépasse le reste à payer'));
                }
                $escompte = 0.0;
                if ($validated['escompte'] ?? false) {
                    $propose = $this->conditions->escompte($facture, Carbon::parse($validated['date_paiement'])->toDateString());
                    if (! $propose || ! $propose['applicable']) {
                        throw new \DomainException($propose
                            ? __('Escompte impossible : le paiement doit être fait au plus tard le :date.', ['date' => Carbon::parse($propose['date_limite'])->format('d/m/Y')])
                            : __('Aucun escompte possible sur cette facture.'));
                    }
                    if (abs((float) $validated['montant'] - $propose['a_payer']) > 0.01) {
                        throw new \DomainException(__("Avec l'escompte, le paiement règle tout le reste : :montant.", ['montant' => number_format($propose['a_payer'], 2, ',', ' ')]));
                    }
                    $escompte = $propose['escompte'];
                }
                // Une carte cadeau n'apporte pas d'argent : elle ne passe pas par la caisse.
                $carte = $validated['mode_paiement'] === 'carte_cadeau'
                    ? $this->cartes->pourPaiement($validated['carte_cadeau'], $facture->devise, (float) $validated['montant'], Carbon::parse($validated['date_paiement'])->toDateString())
                    : null;
                $sessionCaisse = $carte ? null : $this->caisses->sessionPourEncaissement($validated['caisse_id'] ?? null);

                $paiement = FacturePaiement::create([
                    'facture_id' => $facture->id,
                    'echeance_id' => $validated['echeance_id'] ?? null,
                    'date_paiement' => $validated['date_paiement'],
                    'montant' => $validated['montant'],
                    'escompte' => $escompte,
                    'mode_paiement' => $carte ? 'autre' : $validated['mode_paiement'],
                    'reference' => $carte ? $carte->numero : ($validated['reference'] ?? null),
                    'notes' => $validated['notes'] ?? null,
                    'enregistre_par' => $request->user()?->id,
                    'caisse_session_id' => $sessionCaisse,
                    'carte_cadeau_id' => $carte?->id,
                ]);
                if ($carte) {
                    $this->cartes->utiliser($carte, $paiement, $request->user()?->id);
                }

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

    /**
     * Bon de livraison de la facture : la première livraison, puis le reste après une livraison partielle. Le bon
     * reprend ce qui n'a pas encore été livré (quantité facturée moins quantités livrées par les bons précédents). La
     * facture couvre déjà toute la quantité : livrer le reste ne refacture rien ; un reste abandonné se règle par une
     * note de crédit.
     */
    public function creerBonLivraison(Request $request, int $id): JsonResponse
    {
        $facture = Facture::with('lignes')->findOrFail($id);

        // Un bon annulé ne compte pas ; un bon livré (en tout ou en partie) est terminé ; un autre bon est en cours.
        if (BonLivraison::where('facture_id', $facture->id)->whereNotIn('statut', ['annule', 'livre_complet', 'livre_partiel'])->exists()) {
            return response()->json(['message' => __('Un bon de livraison existe déjà')], 422);
        }

        if (! in_array($facture->statut, ['emise', 'partiellement_payee', 'payee'])) {
            return response()->json(['message' => __('La facture doit être émise')], 422);
        }

        $restes = $this->resteALivrer($facture);
        if (array_sum($restes) <= 0) {
            return response()->json(['message' => __('La facture est entièrement livrée.')], 422);
        }

        $tenantId = $request->attributes->get('tenant')->id;
        $configBon = Configuration::pourEntite('bon_livraison', $tenantId);
        if (! $configBon || ! $configBon->auto_increment) {
            return response()->json(['success' => false, 'message' => __('Numéro requis')], 422);
        }
        $bon = DB::transaction(function () use ($facture, $tenantId, $restes) {
            $numeroBon = Configuration::consommerNumero('bon_livraison', $tenantId);

            $bon = BonLivraison::create([
                'tenant_id' => $tenantId,
                'numero' => $numeroBon,
                'facture_id' => $facture->id,
                'mode_livraison' => 'entreprise',
                // Transporteur et numéro de suivi de la facture.
                'transporteur_id' => $facture->transporteur_id,
                'numero_suivi' => $facture->numero_suivi,
                'numero_commande_client' => $facture->numero_commande_client,
                'statut' => 'cree',
            ]);

            foreach ($facture->lignes as $ligne) {
                if (($restes[$ligne->id] ?? 0) <= 0) {
                    continue;
                }
                BonLivraisonLigne::create([
                    'bon_id' => $bon->id,
                    'produit_id' => $ligne->produit_id,
                    'quantite_a_livrer' => $restes[$ligne->id],
                    // Livré dans l'unité facturée.
                    'unite' => $ligne->unite,
                    'facteur' => $ligne->facteur,
                ]);
            }

            return $bon;
        });

        return response()->json($bon->load('lignes'), 201);
    }

    /**
     * Reste à livrer de chaque ligne de la facture (id de ligne => quantité). Les bons livrés de la facture sont
     * rapprochés de ses lignes par produit et unité (une caisse ne se confond pas avec l'unité), dans l'ordre des
     * lignes quand un même produit revient plusieurs fois.
     */
    private function resteALivrer(Facture $facture): array
    {
        $livres = BonLivraisonLigne::query()
            ->whereIn('bon_id', BonLivraison::where('facture_id', $facture->id)->whereIn('statut', ['livre_complet', 'livre_partiel'])->select('id'))
            ->get()
            ->groupBy(fn (BonLivraisonLigne $l) => $l->produit_id.'|'.$l->unite)
            ->map(fn ($lignes) => (float) $lignes->sum('quantite_livree'))
            ->all();

        $restes = [];
        foreach ($facture->lignes as $ligne) {
            $cle = $ligne->produit_id.'|'.$ligne->unite;
            $dejaLivre = min((float) ($livres[$cle] ?? 0), (float) $ligne->quantite);
            $livres[$cle] = ($livres[$cle] ?? 0) - $dejaLivre;
            $restes[$ligne->id] = max(0, $ligne->quantite - $dejaLivre);
        }

        return $restes;
    }

    public function destroy(int $id): JsonResponse
    {
        $facture = Facture::findOrFail($id);

        if ($facture->statut !== 'brouillon') {
            return response()->json(['message' => __('Seules les factures en brouillon peuvent être supprimées.')], 422);
        }

        $facture->delete();

        return response()->json(['message' => __('Facture supprimée.')]);
    }
}
