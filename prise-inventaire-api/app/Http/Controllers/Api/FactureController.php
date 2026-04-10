<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BonLivraison;
use App\Models\BonLivraisonLigne;
use App\Models\ComClientEntete;
use App\Models\Configuration;
use App\Models\Facture;
use App\Models\FactureEcheance;
use App\Models\FactureLigne;
use App\Models\FacturePaiement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FactureController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $query = Facture::with(['client', 'commande'])->where('tenant_id', $tenantId);

        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
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
        $facture = Facture::with(['client', 'commande', 'lignes', 'echeances', 'paiements', 'bonLivraison'])->findOrFail($id);
        return response()->json($facture);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id'               => 'required|exists:clients,id',
            'date_facture'            => 'required|date',
            'date_echeance'           => 'nullable|date',
            'notes'                   => 'nullable|string',
            'lignes'                  => 'required|array|min:1',
            'lignes.*.produit_id'     => 'required|integer',
            'lignes.*.quantite'       => 'required|integer|min:1',
            'lignes.*.prix_unitaire_ht' => 'required|numeric|min:0',
            'lignes.*.taux_tva'       => 'nullable|numeric',
            'lignes.*.remise_ligne'   => 'nullable|numeric|min:0|max:100',
        ]);

        $tenantId = $request->attributes->get('tenant')->id;
        $config = Configuration::pourEntite('facture', $tenantId);
        if (!$config || !$config->auto_increment) {
            return response()->json(['success' => false, 'message' => 'Numéro requis'], 422);
        }
        $numero = $config->genererNumero();
        $config->incrementer();

        $facture = DB::transaction(function () use ($validated, $numero) {
            $montantHt = 0; $montantTva = 0; $montantTtc = 0;

            $lignesCalculees = array_map(function ($l) use (&$montantHt, &$montantTva, &$montantTtc) {
                $tva    = $l['taux_tva'] ?? 20;
                $remise = $l['remise_ligne'] ?? 0;
                $ht  = $l['quantite'] * $l['prix_unitaire_ht'] * (1 - $remise / 100);
                $tvaM = $ht * ($tva / 100);
                $ttc = $ht + $tvaM;
                $montantHt += $ht; $montantTva += $tvaM; $montantTtc += $ttc;
                return compact('ht', 'tvaM', 'ttc', 'tva', 'remise') + $l;
            }, $validated['lignes']);

            $facture = Facture::create([
                'tenant_id'     => $tenantId,
                'numero'        => $numero,
                'client_id'     => $validated['client_id'],
                'date_facture'  => $validated['date_facture'],
                'date_echeance' => $validated['date_echeance'] ?? null,
                'statut'        => 'brouillon',
                'montant_ht'    => $montantHt,
                'montant_tva'   => $montantTva,
                'montant_ttc'   => $montantTtc,
                'reste_a_payer' => $montantTtc,
                'notes'         => $validated['notes'] ?? null,
            ]);

            foreach ($lignesCalculees as $l) {
                FactureLigne::create([
                    'facture_id'       => $facture->id,
                    'produit_id'       => $l['produit_id'],
                    'quantite'         => $l['quantite'],
                    'prix_unitaire_ht' => $l['prix_unitaire_ht'],
                    'taux_tva'         => $l['tva'],
                    'remise_ligne'     => $l['remise'],
                    'montant_ht'       => $l['ht'],
                    'montant_tva'      => $l['tvaM'],
                    'montant_ttc'      => $l['ttc'],
                ]);
            }

            return $facture;
        });

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
        if (!$configFacture || !$configFacture->auto_increment) {
            return response()->json(['success' => false, 'message' => 'Numéro requis'], 422);
        }
        $numeroFacture = $configFacture->genererNumero();
        $configFacture->incrementer();

        $facture = DB::transaction(function () use ($commande, $numeroFacture, $tenantId) {
            $facture = Facture::create([
                'tenant_id' => $tenantId,
                'numero' => $numeroFacture,
                'commande_id' => $commande->id,
                'client_id' => $commande->client_id,
                'date_facture' => now(),
                'statut' => 'brouillon',
                'montant_ht' => $commande->montant_ht,
                'montant_tva' => $commande->montant_tva,
                'montant_ttc' => $commande->montant_ttc,
                'reste_a_payer' => $commande->montant_ttc,
            ]);

            foreach ($commande->lignes as $ligne) {
                FactureLigne::create([
                    'facture_id' => $facture->id,
                    'produit_id' => $ligne->produit_id,
                    'quantite' => $ligne->quantite,
                    'prix_unitaire_ht' => $ligne->prix_unitaire_ht,
                    'taux_tva' => $ligne->taux_tva,
                    'remise_ligne' => $ligne->remise_ligne,
                    'montant_ht' => $ligne->montant_ht,
                    'montant_tva' => $ligne->montant_ttc - $ligne->montant_ht,
                    'montant_ttc' => $ligne->montant_ttc,
                ]);
            }

            $conditions = $commande->client->conditionsPaiement;
            if ($conditions->isEmpty()) {
                FactureEcheance::create([
                    'facture_id' => $facture->id,
                    'date_echeance' => now()->addDays(30),
                    'montant' => $facture->montant_ttc,
                    'ordre' => 1,
                ]);
                $facture->date_echeance = now()->addDays(30);
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

        return response()->json($facture);
    }

    public function enregistrerPaiement(Request $request, int $id): JsonResponse
    {
        $facture = Facture::findOrFail($id);

        if (!in_array($facture->statut, ['emise', 'partiellement_payee'])) {
            return response()->json(['message' => 'Cette facture ne peut pas recevoir de paiement'], 422);
        }

        $validated = $request->validate([
            'montant' => 'required|numeric|min:0.01',
            'date_paiement' => 'required|date',
            'mode_paiement' => 'required|in:especes,cheque,virement,carte,autre',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'echeance_id' => 'nullable|exists:facture_echeances,id',
        ]);

        if ($validated['montant'] > $facture->reste_a_payer) {
            return response()->json(['message' => 'Le montant dépasse le reste à payer'], 422);
        }

        $paiement = FacturePaiement::create([
            'facture_id' => $facture->id,
            'echeance_id' => $validated['echeance_id'] ?? null,
            'date_paiement' => $validated['date_paiement'],
            'montant' => $validated['montant'],
            'mode_paiement' => $validated['mode_paiement'],
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'enregistre_par' => $request->user()?->id,
        ]);

        return response()->json($facture->fresh(['paiements']));
    }

    public function creerBonLivraison(Request $request, int $id): JsonResponse
    {
        $facture = Facture::with('lignes')->findOrFail($id);

        if ($facture->bonLivraison) {
            return response()->json(['message' => 'Un bon de livraison existe déjà'], 422);
        }

        if (!in_array($facture->statut, ['emise', 'partiellement_payee', 'payee'])) {
            return response()->json(['message' => 'La facture doit être émise'], 422);
        }

        $tenantId = $request->attributes->get('tenant')->id;
        $configBon = Configuration::pourEntite('bon_livraison', $tenantId);
        if (!$configBon || !$configBon->auto_increment) {
            return response()->json(['success' => false, 'message' => 'Numéro requis'], 422);
        }
        $numeroBon = $configBon->genererNumero();
        $configBon->incrementer();

        $bon = DB::transaction(function () use ($facture, $numeroBon, $tenantId) {
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
