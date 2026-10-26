<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\ComClientLigne;
use App\Models\Configuration;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Services\ClientsDePassage;
use App\Services\DevisesVente;
use App\Services\EntetesVente;
use App\Services\FacturesVente;
use App\Services\LivraisonDocuments;
use App\Services\Portail\AccesPortail;
use App\Services\ReponsesDevis;
use App\Services\StatutsPersonnalises;
use App\Services\TaxesDocument;
use App\Services\UnitesProduits;
use App\Support\DateDuJour;
use App\Support\Droits;
use App\Support\TauxTaxe;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DevisController extends Controller
{
    public function __construct(private readonly TaxesDocument $taxes) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        // Un devis envoyé dont la validité est passée devient « expiré » (le filtre restait toujours vide).
        Devis::where('tenant_id', $tenantId)->where('statut', Devis::STATUT_ENVOYE)
            ->whereDate('date_validite', '<', now()->toDateString())->update(['statut' => Devis::STATUT_EXPIRE]);
        $query = Devis::with(['client', 'lignes.produit', 'createdBy', 'commande:id,numero', 'facture:id,numero', 'representant:id,nom', 'transporteur:id,nom', 'contact:id,nom,telephone,email', StatutsPersonnalises::COLONNES])->where('tenant_id', $tenantId);

        if ($request->has('statut') && $request->statut) {
            $query->where('statut', $request->statut);
        }

        if ($request->has('client_id') && $request->client_id) {
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

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('numero', 'like', "%{$search}%")
                    ->orWhere('passage_nom', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($q2) => $q2->where('raison_sociale', 'like', "%{$search}%"));
            });
        }

        $devis = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

        return response()->json($devis);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', TenantRule::exists('clients')],
            'date_devis' => 'required|date',
            'date_validite' => 'required|date|after_or_equal:date_devis',
            'notes' => 'nullable|string',
            'lignes' => 'required|array|min:1',
            'lignes.*.produit_id' => ['required', TenantRule::exists('produits')],
            'lignes.*.quantite' => 'required|integer|min:1',
            'lignes.*.prix_unitaire' => 'required|numeric|min:0',
            // Unité de vente du produit (la caisse de 12…) ; vide : son unité de stock.
            'lignes.*.unite' => 'nullable|string|max:20',
            ...DevisesVente::regles(),
            ...ClientsDePassage::regles(),
            ...EntetesVente::regles(),
            ...LivraisonDocuments::regles(),
        ]);
        $validated['lignes'] = app(UnitesProduits::class)->completerLignes($validated['lignes']);
        // Devise du devis : celle demandée, sinon celle du client ; prix saisis dans cette devise. Représentant : idem.
        $client = Client::find($validated['client_id']);
        $devise = app(DevisesVente::class)->pourDocument($validated['devise'] ?? null, $validated['taux_change'] ?? null, $client)
            + app(EntetesVente::class)->pourCreation($validated, $client) + app(LivraisonDocuments::class)->pourCreation($validated, $client);

        $tenantId = $request->attributes->get('tenant')->id;
        $config = Configuration::pourEntite('devis', $tenantId);
        if (! $config || ! $config->auto_increment) {
            return response()->json(['success' => false, 'message' => __('Configuration numéro devis manquante')], 422);
        }
        $devis = DB::transaction(function () use ($validated, $request, $tenantId, $devise) {
            $numero = Configuration::consommerNumero('devis', $tenantId);

            $devis = Devis::create($devise + app(ClientsDePassage::class)->coordonnees($validated, Client::find($validated['client_id'])) + [
                'tenant_id' => $tenantId,
                'numero' => $numero,
                'client_id' => $validated['client_id'],
                'date_devis' => $validated['date_devis'],
                'date_validite' => $validated['date_validite'],
                'notes' => $validated['notes'] ?? null,
                'statut' => Devis::STATUT_BROUILLON,
                'created_by' => $request->user()->id,
            ]);

            foreach ($validated['lignes'] as $ligne) {
                DevisLigne::create([
                    'devis_id' => $devis->id,
                    'produit_id' => $ligne['produit_id'],
                    'quantite' => $ligne['quantite'],
                    'prix_unitaire' => $ligne['prix_unitaire'],
                    'montant_ligne' => $ligne['quantite'] * $ligne['prix_unitaire'],
                    'unite' => $ligne['unite'],
                    'facteur' => $ligne['facteur'],
                ]);
            }

            $devis->recalculerTotal();

            return $devis;
        });

        return response()->json($devis->load(['client', 'lignes.produit']), 201);
    }

    public function show(Devis $devis): JsonResponse
    {
        $devis->load(['client', 'lignes.produit', 'createdBy', 'commande:id,numero', 'facture:id,numero', 'representant:id,nom', 'transporteur:id,nom', 'contact:id,nom,telephone,email', StatutsPersonnalises::COLONNES]);

        return response()->json($devis);
    }

    public function update(Request $request, Devis $devis): JsonResponse
    {
        if ($devis->statut !== Devis::STATUT_BROUILLON) {
            return response()->json(['message' => __('Seuls les devis en brouillon peuvent être modifiés.')], 422);
        }

        $validated = $request->validate([
            'client_id' => ['required', TenantRule::exists('clients')],
            'date_devis' => 'required|date',
            'date_validite' => 'required|date|after_or_equal:date_devis',
            'notes' => 'nullable|string',
            'lignes' => 'required|array|min:1',
            'lignes.*.id' => 'nullable|exists:devis_lignes,id',
            'lignes.*.produit_id' => ['required', TenantRule::exists('produits')],
            'lignes.*.quantite' => 'required|integer|min:1',
            'lignes.*.prix_unitaire' => 'required|numeric|min:0',
            'lignes.*.unite' => 'nullable|string|max:20',
            ...ClientsDePassage::regles(),
            ...EntetesVente::regles($devis),
            ...LivraisonDocuments::regles($devis),
        ]);
        $validated['lignes'] = app(UnitesProduits::class)->completerLignes($validated['lignes']);

        DB::transaction(function () use ($validated, $devis) {
            $devis->update(app(ClientsDePassage::class)->pourModification($validated, $devis, Client::find($validated['client_id']))
                + app(EntetesVente::class)->pourModification($validated) + app(LivraisonDocuments::class)->pourModification($validated, $devis) + [
                    'client_id' => $validated['client_id'],
                    'date_devis' => $validated['date_devis'],
                    'date_validite' => $validated['date_validite'],
                    'notes' => $validated['notes'] ?? null,
                ]);

            $existingIds = collect($validated['lignes'])->pluck('id')->filter()->toArray();
            $devis->lignes()->whereNotIn('id', $existingIds)->delete();

            foreach ($validated['lignes'] as $ligne) {
                $data = [
                    'produit_id' => $ligne['produit_id'],
                    'quantite' => $ligne['quantite'],
                    'prix_unitaire' => $ligne['prix_unitaire'],
                    'montant_ligne' => $ligne['quantite'] * $ligne['prix_unitaire'],
                    'unite' => $ligne['unite'],
                    'facteur' => $ligne['facteur'],
                ];
                if (isset($ligne['id'])) {
                    // Scoper au devis courant (tenant-scopé) : empêche de modifier la ligne d'un autre tenant par id.
                    $devis->lignes()->where('id', $ligne['id'])->update($data);
                } else {
                    DevisLigne::create(array_merge($data, ['devis_id' => $devis->id]));
                }
            }

            $devis->recalculerTotal();
        });

        return response()->json($devis->fresh()->load(['client', 'lignes.produit']));
    }

    public function envoyer(Devis $devis): JsonResponse
    {
        if ($devis->statut !== Devis::STATUT_BROUILLON) {
            return response()->json(['message' => __('Seuls les devis en brouillon peuvent être envoyés.')], 422);
        }

        $devis->statut = Devis::STATUT_ENVOYE;
        $devis->envoye_le = now();
        $devis->save();

        return response()->json(['message' => __('Devis envoyé.'), 'devis' => $devis->load(['client', 'lignes.produit'])]);
    }

    public function accepter(Devis $devis): JsonResponse
    {
        if ($devis->statut !== Devis::STATUT_ENVOYE) {
            return response()->json(['message' => __('Seuls les devis envoyés peuvent être acceptés.')], 422);
        }
        if ($devis->estExpire()) {
            $devis->update(['statut' => Devis::STATUT_EXPIRE]);

            return response()->json(['message' => __('Ce devis a expiré le :date : faites-en un nouveau.', ['date' => $devis->date_validite->format('d/m/Y')])], 422);
        }

        // Réponse notée par un employé (le client a répondu autrement qu'en ligne).
        $devis->fill(['statut' => Devis::STATUT_ACCEPTE, 'reponse_le' => now(), 'reponse_en_ligne' => false, 'reponse_nom' => null])->save();

        return response()->json(['message' => __('Devis accepté.'), 'devis' => $devis->load(['client', 'lignes.produit'])]);
    }

    public function refuser(Devis $devis): JsonResponse
    {
        if ($devis->statut !== Devis::STATUT_ENVOYE) {
            return response()->json(['message' => __('Seuls les devis envoyés peuvent être refusés.')], 422);
        }

        $devis->fill(['statut' => Devis::STATUT_REFUSE, 'reponse_le' => now(), 'reponse_en_ligne' => false, 'reponse_nom' => null])->save();

        return response()->json(['message' => __('Devis refusé.'), 'devis' => $devis->load(['client', 'lignes.produit'])]);
    }

    /**
     * Lien de réponse en ligne du devis, à transmettre au client : il l'accepte ou le refuse sur le site de l'espace
     * client. Le partager revient à l'envoyer (un brouillon passe à Envoyé).
     */
    public function lienReponse(Request $request, Devis $devis, ReponsesDevis $reponses, AccesPortail $acces): JsonResponse
    {
        $donnees = $request->validate(['origine' => ['nullable', 'string', 'max:255']]);
        if (! $reponses->ouvert($devis)) {
            return response()->json(['message' => __('Ce devis ne peut plus recevoir de réponse (déjà décidé ou expiré).')], 422);
        }
        if (! $reponses->active()) {
            return response()->json(['message' => __('La réponse en ligne aux devis est désactivée dans les paramètres des courriels.')], 422);
        }
        // Adresse du site de l'espace client connue du back-office, comme pour les invitations au portail.
        $acces->memoriserAdresse($donnees['origine'] ?? null);
        $lien = $reponses->lien($devis);
        if (! $lien) {
            return response()->json(['message' => __("Adresse du site de l'espace client inconnue : réglez-la dans Ventes → Espace client.")], 422);
        }
        $reponses->marquerEnvoye($devis->id);

        return response()->json(['url' => $lien, 'devis' => $devis->fresh()->load(['client', 'lignes.produit'])]);
    }

    public function convertirEnCommande(Request $request, Devis $devis): JsonResponse
    {
        if ($devis->statut !== Devis::STATUT_ACCEPTE) {
            return response()->json(['message' => __('Seuls les devis acceptés peuvent être convertis en commande.')], 422);
        }
        if ($deja = $this->dejaConverti($devis)) {
            return $deja;
        }

        $devis->load('lignes');
        // Date de la commande : celle de l'usager (envoyée par l'écran), sinon le jour de l'entreprise.
        $date = DateDuJour::demandee($request);

        $tenantId = $devis->tenant_id;
        $configCommande = Configuration::pourEntite('commande', $tenantId);
        if (! $configCommande || ! $configCommande->auto_increment) {
            return response()->json(['success' => false, 'message' => __('Configuration numéro commande manquante')], 422);
        }

        $commande = DB::transaction(function () use ($devis, $request, $tenantId, $date) {
            // Verrou sur le devis : deux conversions simultanées ne créent pas deux commandes.
            $verrouille = Devis::whereKey($devis->id)->lockForUpdate()->first();
            if (($verrouille?->commande_id && $verrouille->commande()->exists()) || ($verrouille?->facture_id && $verrouille->facture()->exists())) {
                return null;
            }

            // Même séquence que la création normale de commande (cohérence + verrou).
            $numero = Configuration::consommerNumero('commande', $tenantId);

            $commande = ComClientEntete::create(app(ClientsDePassage::class)->copier($devis) + app(EntetesVente::class)->copier($devis) + app(LivraisonDocuments::class)->copier($devis) + [
                'numero' => $numero,
                'client_id' => $devis->client_id,
                // La commande reste dans la devise du devis, à son taux.
                'devise' => $devis->devise,
                'taux_change' => $devis->taux_change ?? 1,
                'date_commande' => $date,
                'statut' => 'brouillon',
                'montant_ht' => 0,
                'montant_tva' => 0,
                'montant_ttc' => 0,
                'notes' => $devis->notes,
                'created_by' => $request->user()->id,
            ]);

            foreach ($devis->lignes as $ligne) {
                // Un devis ne porte pas de taxe : la commande les calcule comme une commande saisie
                // directement (groupe de taxes par défaut de l'entreprise s'il existe, sinon taux par défaut).
                $ligneCommande = new ComClientLigne([
                    'produit_id' => $ligne->produit_id,
                    'quantite' => $ligne->quantite,
                    'prix_unitaire_ht' => $ligne->prix_unitaire,
                    'taux_tva' => TauxTaxe::parDefaut(),
                    'remise_ligne' => 0,
                    // Même unité de vente, au facteur du devis.
                    'unite' => $ligne->unite,
                    'facteur' => $ligne->facteur,
                ]);
                $ligneCommande->calculerMontants();
                $detail = $this->taxes->appliquer($ligneCommande, $this->taxes->groupePour(['produit_id' => $ligne->produit_id]));
                $commande->lignes()->save($ligneCommande);
                $this->taxes->enregistrer('commande_client', $ligneCommande->id, $detail);
            }

            $commande->load('lignes');
            $commande->calculerMontants();
            $commande->save();
            $devis->update(['commande_id' => $commande->id]);

            return $commande;
        });

        if (! $commande) {
            return $this->dejaConverti($devis->refresh()) ?? response()->json(['message' => __("Conversion impossible pour l'instant : réessayez.")], 409);
        }

        return response()->json([
            'message' => __('Devis converti en commande.'),
            'commande' => $commande->load(['client', 'lignes']),
        ]);
    }

    /** Réponse 422 si le devis a déjà sa commande (toujours existante), sinon null. */
    private function dejaConverti(Devis $devis): ?JsonResponse
    {
        $commande = $devis->commande_id ? $devis->commande()->first(['id', 'numero']) : null;
        if ($commande) {
            return response()->json(['message' => __('Ce devis a déjà été converti en commande :numero.', ['numero' => $commande->numero]), 'commande' => $commande], 422);
        }
        $facture = $devis->facture_id ? $devis->facture()->first(['id', 'numero']) : null;

        return $facture
            ? response()->json(['message' => __('Ce devis a déjà été facturé : facture :numero.', ['numero' => $facture->numero]), 'facture' => $facture], 422)
            : null;
    }

    /**
     * Facture brouillon issue d'un devis accepté, sans passer par une commande (comme dans fripv2) : mêmes lignes, prix,
     * devise et taux, coordonnées du client de passage. Un devis ne se convertit qu'une fois, en commande ou en facture.
     */
    public function facturer(Request $request, Devis $devis, FacturesVente $factures, Droits $droits): JsonResponse
    {
        // Créer une facture demande aussi ce droit.
        if (! $droits->permet($request->user(), 'factures', 'creer')) {
            return response()->json(['message' => $droits->messageRefus('factures', 'creer'), 'module' => 'factures', 'action' => 'creer'], 403);
        }
        if ($devis->statut !== Devis::STATUT_ACCEPTE) {
            return response()->json(['message' => __('Seuls les devis acceptés peuvent être facturés.')], 422);
        }
        if ($deja = $this->dejaConverti($devis)) {
            return $deja;
        }
        $config = Configuration::pourEntite('facture', $devis->tenant_id);
        if (! $config || ! $config->auto_increment) {
            return response()->json(['success' => false, 'message' => __('Configuration numéro facture manquante')], 422);
        }

        $date = DateDuJour::demandee($request);
        $facture = DB::transaction(function () use ($devis, $factures, $date) {
            // Verrou sur le devis : deux conversions simultanées ne créent pas deux documents.
            $verrouille = Devis::whereKey($devis->id)->lockForUpdate()->first();
            if ($verrouille->commande_id || $verrouille->facture_id) {
                return null;
            }
            $devis->load('lignes');
            $facture = $factures->creer(app(ClientsDePassage::class)->copier($devis) + app(EntetesVente::class)->copier($devis) + app(LivraisonDocuments::class)->copier($devis) + [
                'client_id' => $devis->client_id,
                'date_facture' => $date,
                // La facture reste dans la devise du devis, à son taux : les montants acceptés ne changent pas.
                'devise' => $devis->devise ?: app(DevisesVente::class)->reference(),
                'taux_change' => $devis->taux_change ?? 1,
                'notes' => $devis->notes,
                'lignes' => $devis->lignes->map(fn (DevisLigne $ligne) => [
                    'produit_id' => $ligne->produit_id,
                    'quantite' => $ligne->quantite,
                    'prix_unitaire_ht' => $ligne->prix_unitaire,
                    'unite' => $ligne->unite,
                    'facteur' => $ligne->facteur,
                ])->all(),
            ], $devis->tenant_id);
            $verrouille->update(['facture_id' => $facture->id]);

            return $facture;
        });

        if (! $facture) {
            return $this->dejaConverti($devis->refresh()) ?? response()->json(['message' => __("Conversion impossible pour l'instant : réessayez.")], 409);
        }

        return response()->json(['message' => __('Devis facturé : facture :numero (brouillon).', ['numero' => $facture->numero]), 'facture' => $facture->load(['client', 'lignes'])], 201);
    }

    /**
     * Nouveau devis brouillon repris d'un autre (comme dans fripv2) : mêmes client, lignes, prix et notes, daté du jour
     * avec la même durée de validité, dans la même devise au taux du jour.
     */
    public function copier(Request $request, Devis $devis): JsonResponse
    {
        $config = Configuration::pourEntite('devis', $devis->tenant_id);
        if (! $config || ! $config->auto_increment) {
            return response()->json(['success' => false, 'message' => __('Configuration numéro devis manquante')], 422);
        }
        $devise = app(DevisesVente::class)->pourDocument($devis->devise ?: app(DevisesVente::class)->reference(), null, Client::find($devis->client_id));

        $copie = DB::transaction(function () use ($devis, $request, $devise) {
            $duree = $devis->date_devis && $devis->date_validite ? (int) $devis->date_devis->diffInDays($devis->date_validite) : 30;
            $aujourdhui = now()->startOfDay();
            $copie = Devis::create($devise + app(ClientsDePassage::class)->copier($devis) + app(EntetesVente::class)->copier($devis) + app(LivraisonDocuments::class)->copier($devis) + [
                'tenant_id' => $devis->tenant_id,
                'numero' => Configuration::consommerNumero('devis', $devis->tenant_id),
                'client_id' => $devis->client_id,
                'date_devis' => $aujourdhui->toDateString(),
                'date_validite' => $aujourdhui->copy()->addDays($duree)->toDateString(),
                'notes' => $devis->notes,
                'statut' => Devis::STATUT_BROUILLON,
                'created_by' => $request->user()->id,
            ]);
            foreach ($devis->lignes as $ligne) {
                DevisLigne::create([
                    'devis_id' => $copie->id,
                    'produit_id' => $ligne->produit_id,
                    'quantite' => $ligne->quantite,
                    'prix_unitaire' => $ligne->prix_unitaire,
                    'montant_ligne' => $ligne->quantite * $ligne->prix_unitaire,
                    'unite' => $ligne->unite,
                    'facteur' => $ligne->facteur,
                ]);
            }
            $copie->recalculerTotal();

            return $copie;
        });

        return response()->json(['message' => __('Devis copié : :numero.', ['numero' => $copie->numero]), 'devis' => $copie->load(['client', 'lignes.produit'])], 201);
    }

    public function destroy(Devis $devis): JsonResponse
    {
        if ($devis->statut !== Devis::STATUT_BROUILLON) {
            return response()->json(['message' => __('Seuls les devis en brouillon peuvent être supprimés.')], 422);
        }

        $devis->delete();

        return response()->json(['message' => __('Devis supprimé.')]);
    }
}
