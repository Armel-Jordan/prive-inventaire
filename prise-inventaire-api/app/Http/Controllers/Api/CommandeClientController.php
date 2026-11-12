<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\ComClientLigne;
use App\Models\Configuration;
use App\Models\MouvementVente;
use App\Models\ProduitLocalisation;
use App\Services\ClientsDePassage;
use App\Services\ConditionsPaiement;
use App\Services\DevisesVente;
use App\Services\DisponibiliteProduits;
use App\Services\EntetesVente;
use App\Services\LivraisonDocuments;
use App\Services\StatutsPersonnalises;
use App\Services\TaxesDocument;
use App\Services\UnitesProduits;
use App\Support\ExportListe;
use App\Support\NumeroCommandeClient;
use App\Support\PoidsDocuments;
use App\Support\StatutsListes;
use App\Support\TauxTaxe;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CommandeClientController extends Controller
{
    public function __construct(private readonly TaxesDocument $taxes) {}

    public function index(Request $request): JsonResponse|Response
    {
        $tenantId = auth()->user()->tenant_id;
        // Statut de livraison calculé (socle S4.7) : le bon en cours de sa facture, et s'il y a des produits à livrer.
        $query = ComClientEntete::with(['client', 'lignes', 'representant:id,nom', 'transporteur:id,nom', 'conditionPaiement:id,libelle', StatutsPersonnalises::COLONNES,
            'facture:id,commande_id,numero', 'facture.bonLivraison:id,facture_id,statut'])->withExists(StatutsListes::aLivrer())->where('tenant_id', $tenantId);

        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
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
        // Prioritaires seulement (l'étoile, socle S4.1).
        if ($request->boolean('prioritaire')) {
            $query->where('prioritaire', true);
        }
        // Documents qui contiennent ce produit (socle S4.7).
        StatutsListes::filtrerProduit($query, $request);
        StatutsListes::filtrerLivraison($query, $request->query('statut_livraison'), 'facture.bonLivraison');
        // Numéro de commande, nom ou code du client, nom noté pour un client de passage (liens « ?recherche= »).
        if ($request->filled('recherche')) {
            $motif = '%'.addcslashes(trim((string) $request->recherche), '%_\\').'%';
            $query->where(fn ($q) => $q->where('numero', 'like', $motif)->orWhere('passage_nom', 'like', $motif)->orWhere('numero_commande_client', 'like', $motif)
                ->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $motif)->orWhere('code', 'like', $motif)));
        }
        if ($request->has('date_debut')) {
            $query->whereDate('date_commande', '>=', $request->date_debut);
        }
        if ($request->has('date_fin')) {
            $query->whereDate('date_commande', '<=', $request->date_fin);
        }

        $query->orderBy('created_at', 'desc');
        // Export Excel de toute la liste filtrée (S5), avec les mêmes droits que sa lecture.
        if (ExportListe::demande($request)) {
            return ExportListe::reponse($query, 'commandes-client', 'Commandes', [
                ['Numéro', 'Number', fn (ComClientEntete $d) => $d->numero],
                ['Date', 'Date', fn (ComClientEntete $d) => $d->date_commande],
                ['Livraison souhaitée', 'Requested delivery', fn (ComClientEntete $d) => $d->date_livraison_souhaitee],
                ['Client', 'Customer', fn (ComClientEntete $d) => filled($d->passage_nom) ? $d->passage_nom : $d->client?->raison_sociale],
                ['N° de commande du client', 'Customer PO number', fn (ComClientEntete $d) => $d->numero_commande_client],
                ['Statut', 'Status', fn (ComClientEntete $d) => ExportListe::statut('commande_client', $d->statut)],
                ['Statut personnalisé', 'Custom status', fn (ComClientEntete $d) => $d->statutPersonnalise?->libelle],
                ['Montant HT', 'Amount excl. tax', fn (ComClientEntete $d) => $d->montant_ht, true],
                ['Taxes', 'Taxes', fn (ComClientEntete $d) => $d->montant_tva, true],
                ['Montant TTC', 'Total', fn (ComClientEntete $d) => $d->montant_ttc, true],
                ['Devise', 'Currency', fn (ComClientEntete $d) => $d->devise],
                ['Représentant', 'Sales rep', fn (ComClientEntete $d) => $d->representant?->nom],
                ['Transporteur', 'Carrier', fn (ComClientEntete $d) => $d->transporteur?->nom],
                ['Prioritaire', 'Priority', fn (ComClientEntete $d) => (bool) $d->prioritaire],
            ]);
        }
        $commandes = $query->paginate($request->get('per_page', 20));
        $commandes->getCollection()->each(fn (ComClientEntete $c) => $c->setAttribute('statut_livraison', StatutsListes::livraison($c->facture?->bonLivraison, (bool) $c->a_livrer)));

        return response()->json($commandes);
    }

    public function show(int $id): JsonResponse
    {
        $commande = ComClientEntete::with(['client', 'lignes', 'facture', 'representant:id,nom', 'transporteur:id,nom,url_suivi', 'contact:id,nom,telephone,email', 'conditionPaiement:id,libelle', 'groupeTaxe:id,code,nom', StatutsPersonnalises::COLONNES])->findOrFail($id);
        // Détail par taxe (vide si les lignes utilisent le calcul au taux unique).
        $commande->setAttribute('recapitulatif_taxes', $this->taxes->recapitulatif('commande_client', $commande->lignes->pluck('id')));
        // Poids estimé (socle S4.1) : celui des produits qui ont un poids.
        $commande->setAttribute('poids_estime_kg', PoidsDocuments::estime($commande->lignes));

        return response()->json($commande);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', TenantRule::exists('clients')],
            'date_commande' => 'required|date',
            'date_livraison_souhaitee' => 'nullable|date|after_or_equal:date_commande',
            'remise_globale' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
            // Numéro de suivi de l'envoi (connaissement), repris par la facture puis le bon de livraison.
            'numero_suivi' => 'nullable|string|max:60',
            'lignes' => 'required|array|min:1',
            // Produit de l'entreprise (un id quelconque, ou celui d'une autre entreprise, était accepté).
            'lignes.*.produit_id' => ['required', 'integer', TenantRule::exists('produits')],
            'lignes.*.quantite' => 'required|integer|min:1',
            'lignes.*.prix_unitaire_ht' => 'required|numeric|min:0',
            // Taux libre (19,25 % au Cameroun…) ; il était limité aux taux français.
            'lignes.*.taux_tva' => 'nullable|numeric|min:0|max:100',
            'lignes.*.groupe_taxe_id' => ['nullable', 'integer', TenantRule::exists('groupes_taxes')->whereNull('deleted_at')],
            'lignes.*.remise_ligne' => 'nullable|numeric|min:0|max:100',
            // Unité de vente du produit (la caisse de 12…) ; vide : son unité de stock.
            'lignes.*.unite' => 'nullable|string|max:20',
            ...DevisesVente::regles(),
            ...ClientsDePassage::regles(),
            ...EntetesVente::regles(),
            ...NumeroCommandeClient::regles(),
            ...LivraisonDocuments::regles(),
            'condition_paiement_id' => ConditionsPaiement::regle(),
        ]);
        $validated['lignes'] = app(UnitesProduits::class)->completerLignes($validated['lignes']);
        // Devise de la commande : celle demandée, sinon celle du client ; prix saisis dans cette devise. Représentant : idem.
        $client = Client::find($validated['client_id']);
        $devise = app(DevisesVente::class)->pourDocument($validated['devise'] ?? null, $validated['taux_change'] ?? null, $client)
            + app(EntetesVente::class)->pourCreation($validated, $client) + app(LivraisonDocuments::class)->pourCreation($validated, $client)
            + app(ConditionsPaiement::class)->pourCreation($validated, $client);

        $tenantId = $request->attributes->get('tenant')->id;
        $config = Configuration::pourEntite('commande', $tenantId);
        if (! $config || ! $config->auto_increment) {
            return response()->json(['success' => false, 'message' => __('Configuration numérotation manquante')], 422);
        }
        $commande = DB::transaction(function () use ($validated, $request, $tenantId, $devise) {
            $numero = Configuration::consommerNumero('commande', $tenantId);

            $commande = ComClientEntete::create($devise + app(ClientsDePassage::class)->coordonnees($validated, Client::find($validated['client_id'])) + [
                'tenant_id' => $tenantId,
                'numero' => $numero,
                'client_id' => $validated['client_id'],
                'date_commande' => $validated['date_commande'],
                'date_livraison_souhaitee' => $validated['date_livraison_souhaitee'] ?? null,
                'remise_globale' => $validated['remise_globale'] ?? 0,
                'notes' => $validated['notes'] ?? null,
                'numero_suivi' => $validated['numero_suivi'] ?? null,
                'numero_commande_client' => NumeroCommandeClient::valeur($validated['numero_commande_client'] ?? null),
                'statut' => 'brouillon',
                'created_by' => $request->user()?->id,
            ]);

            foreach ($validated['lignes'] as $ligneData) {
                $ligne = new ComClientLigne([
                    'produit_id' => $ligneData['produit_id'],
                    'quantite' => $ligneData['quantite'],
                    'prix_unitaire_ht' => $ligneData['prix_unitaire_ht'],
                    'taux_tva' => $ligneData['taux_tva'] ?? TauxTaxe::parDefaut(),
                    'remise_ligne' => $ligneData['remise_ligne'] ?? 0,
                    'unite' => $ligneData['unite'],
                    'facteur' => $ligneData['facteur'],
                ]);
                $ligne->calculerMontants();
                // Groupe de la ligne, sinon celui de la commande (celui du client par défaut), sinon celui par défaut.
                $detail = $this->taxes->appliquer($ligne, $this->taxes->groupePour($ligneData, $devise['groupe_taxe_id'] ?? null));
                $commande->lignes()->save($ligne);
                $this->taxes->enregistrer('commande_client', $ligne->id, $detail);
            }

            $commande->calculerMontants();
            $commande->save();

            return $commande;
        });

        return response()->json($commande->load('lignes'), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $commande = ComClientEntete::findOrFail($id);

        if ($commande->statut !== 'brouillon') {
            return response()->json(['message' => __('Seules les commandes en brouillon peuvent être modifiées')], 422);
        }

        $validated = $request->validate([
            'client_id' => ['sometimes', TenantRule::exists('clients')],
            'date_commande' => 'sometimes|date',
            'date_livraison_souhaitee' => 'nullable|date',
            'remise_globale' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
            'numero_suivi' => 'nullable|string|max:60',
            'lignes' => 'sometimes|array|min:1',
            'lignes.*.produit_id' => ['required', 'integer', TenantRule::exists('produits')],
            'lignes.*.quantite' => 'required|integer|min:1',
            'lignes.*.prix_unitaire_ht' => 'required|numeric|min:0',
            'lignes.*.taux_tva' => 'nullable|numeric|min:0|max:100',
            'lignes.*.groupe_taxe_id' => ['nullable', 'integer', TenantRule::exists('groupes_taxes')->whereNull('deleted_at')],
            'lignes.*.remise_ligne' => 'nullable|numeric|min:0|max:100',
            'lignes.*.unite' => 'nullable|string|max:20',
            ...ClientsDePassage::regles(),
            ...EntetesVente::regles($commande),
            ...NumeroCommandeClient::regles($commande),
            ...LivraisonDocuments::regles($commande),
            'condition_paiement_id' => ConditionsPaiement::regle($commande),
        ]);
        if (isset($validated['lignes'])) {
            $validated['lignes'] = app(UnitesProduits::class)->completerLignes($validated['lignes']);
        }

        $groupeAvant = $commande->groupe_taxe_id;
        DB::transaction(function () use ($commande, $validated, $groupeAvant) {
            // Coordonnées du client de passage : seulement pour lui (changer de client les efface ; non transmises, elles restent).
            $commande->update(app(ClientsDePassage::class)->pourModification($validated, $commande, Client::find($validated['client_id'] ?? $commande->client_id))
                + app(EntetesVente::class)->pourModification($validated) + app(LivraisonDocuments::class)->pourModification($validated, $commande)
                + app(ConditionsPaiement::class)->pourModification($validated, $commande, Client::find($validated['client_id'] ?? $commande->client_id)));
            // Client et date se modifient aussi ; une note vidée est effacée (elle était conservée).
            $commande->update([
                'client_id' => $validated['client_id'] ?? $commande->client_id,
                'date_commande' => $validated['date_commande'] ?? $commande->date_commande,
                'date_livraison_souhaitee' => array_key_exists('date_livraison_souhaitee', $validated) ? $validated['date_livraison_souhaitee'] : $commande->date_livraison_souhaitee,
                'remise_globale' => $validated['remise_globale'] ?? $commande->remise_globale,
                'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $commande->notes,
                'numero_suivi' => array_key_exists('numero_suivi', $validated) ? $validated['numero_suivi'] : $commande->numero_suivi,
                'numero_commande_client' => array_key_exists('numero_commande_client', $validated)
                    ? NumeroCommandeClient::valeur($validated['numero_commande_client']) : $commande->numero_commande_client,
            ]);

            if (isset($validated['lignes'])) {
                $this->taxes->supprimer('commande_client', $commande->lignes()->pluck('id'));
                $commande->lignes()->delete();
                foreach ($validated['lignes'] as $ligneData) {
                    $ligne = new ComClientLigne([
                        'produit_id' => $ligneData['produit_id'],
                        'quantite' => $ligneData['quantite'],
                        'prix_unitaire_ht' => $ligneData['prix_unitaire_ht'],
                        'taux_tva' => $ligneData['taux_tva'] ?? TauxTaxe::parDefaut(),
                        'remise_ligne' => $ligneData['remise_ligne'] ?? 0,
                        'unite' => $ligneData['unite'],
                        'facteur' => $ligneData['facteur'],
                    ]);
                    $ligne->calculerMontants();
                    $detail = $this->taxes->appliquer($ligne, $this->taxes->groupePour($ligneData, $commande->groupe_taxe_id));
                    $commande->lignes()->save($ligne);
                    $this->taxes->enregistrer('commande_client', $ligne->id, $detail);
                }
            } elseif ((int) $commande->groupe_taxe_id !== (int) $groupeAvant) {
                // Groupe de taxes changé sans renvoyer les lignes : leurs taxes sont refaites (socle S4.1).
                $this->taxes->reappliquer('commande_client', $commande->lignes, $commande->groupe_taxe_id);
            }

            $commande->calculerMontants();
            $commande->save();
        });

        return response()->json($commande->load('lignes'));
    }

    public function soumettre(int $id): JsonResponse
    {
        $commande = ComClientEntete::findOrFail($id);

        if ($commande->statut !== 'brouillon') {
            return response()->json(['message' => __('Cette commande ne peut pas être soumise')], 422);
        }

        $commande->statut = 'en_attente';
        $commande->save();

        return response()->json($commande);
    }

    public function accepter(Request $request, int $id): JsonResponse
    {
        $commande = ComClientEntete::with('lignes', 'client')->findOrFail($id);

        if ($commande->statut !== 'en_attente') {
            return response()->json(['message' => __('Cette commande ne peut pas être acceptée')], 422);
        }

        $client = $commande->client;
        // Encours dans la devise de l'entreprise : la commande à son taux.
        if (! $client->peutCommander((float) $commande->montant_ttc * (float) ($commande->taux_change ?: 1))) {
            return response()->json([
                'message' => __("Dépassement de l'encours maximum autorisé"),
                'encours_actuel' => $client->encours_actuel,
                'encours_max' => $client->encours_max,
                'montant_commande' => $commande->montant_ttc,
            ], 422);
        }

        DB::transaction(function () use ($commande, $request) {
            $commande->statut = 'acceptee';
            $commande->validee_par = $request->user()?->id;
            $commande->save();

            foreach ($commande->lignes as $ligne) {
                // En unités de stock (une caisse de 12 réserve 12 unités).
                $quantite = (int) round($ligne->quantite * $ligne->facteurStock());
                MouvementVente::creerMouvement(
                    $ligne->produit_id,
                    'reservation',
                    $quantite,
                    null, null, null, null,
                    'commande', $commande->id,
                    "Réservation pour commande {$commande->numero}",
                    $request->user()?->id
                );

                ProduitLocalisation::reserver($ligne->produit_id, $quantite, $commande->id);
            }
        });
        // Produits dont le stock ne couvre pas les commandes acceptées (celle-ci comprise) : l'écran propose de noter
        // des reliquats. L'acceptation n'est pas bloquée.
        $commande->setAttribute('manques', app(DisponibiliteProduits::class)->manquesDeCommande($commande));

        return response()->json($commande);
    }

    public function refuser(Request $request, int $id): JsonResponse
    {
        $commande = ComClientEntete::findOrFail($id);

        if ($commande->statut !== 'en_attente') {
            return response()->json(['message' => __('Cette commande ne peut pas être refusée')], 422);
        }

        $validated = $request->validate([
            'motif_refus' => 'required|string|max:500',
        ]);

        $commande->statut = 'refusee';
        $commande->motif_refus = $validated['motif_refus'];
        $commande->validee_par = $request->user()?->id;
        $commande->save();

        return response()->json($commande);
    }

    public function destroy(int $id): JsonResponse
    {
        $commande = ComClientEntete::findOrFail($id);

        if ($commande->statut !== 'brouillon') {
            return response()->json(['message' => __('Seules les commandes en brouillon peuvent être supprimées')], 422);
        }

        $commande->delete();

        return response()->json(['message' => __('Commande supprimée')]);
    }
}
