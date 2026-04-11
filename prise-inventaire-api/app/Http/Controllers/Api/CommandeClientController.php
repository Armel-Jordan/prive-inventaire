<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComClientEntete;
use App\Models\ComClientLigne;
use App\Models\Configuration;
use App\Models\MouvementVente;
use App\Models\ProduitLocalisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommandeClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $query = ComClientEntete::with(['client', 'lignes'])->where('tenant_id', $tenantId);

        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }
        if ($request->has('client_id')) {
            $query->where('client_id', $request->client_id);
        }
        if ($request->has('date_debut')) {
            $query->whereDate('date_commande', '>=', $request->date_debut);
        }
        if ($request->has('date_fin')) {
            $query->whereDate('date_commande', '<=', $request->date_fin);
        }

        $commandes = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 20));

        return response()->json($commandes);
    }

    public function show(int $id): JsonResponse
    {
        $commande = ComClientEntete::with(['client', 'lignes', 'facture'])->findOrFail($id);

        return response()->json($commande);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'date_commande' => 'required|date',
            'date_livraison_souhaitee' => 'nullable|date|after_or_equal:date_commande',
            'remise_globale' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
            'lignes' => 'required|array|min:1',
            'lignes.*.produit_id' => 'required|integer',
            'lignes.*.quantite' => 'required|integer|min:1',
            'lignes.*.prix_unitaire_ht' => 'required|numeric|min:0',
            'lignes.*.taux_tva' => 'nullable|numeric|in:0,5.5,10,20',
            'lignes.*.remise_ligne' => 'nullable|numeric|min:0|max:100',
        ]);

        $tenantId = $request->attributes->get('tenant')->id;
        $config = Configuration::pourEntite('commande', $tenantId);
        if (! $config || ! $config->auto_increment) {
            return response()->json(['success' => false, 'message' => 'Configuration numérotation manquante'], 422);
        }
        $numero = $config->genererNumero();
        $config->incrementer();

        $commande = DB::transaction(function () use ($validated, $request, $numero, $tenantId) {
            $commande = ComClientEntete::create([
                'tenant_id' => $tenantId,
                'numero' => $numero,
                'client_id' => $validated['client_id'],
                'date_commande' => $validated['date_commande'],
                'date_livraison_souhaitee' => $validated['date_livraison_souhaitee'] ?? null,
                'remise_globale' => $validated['remise_globale'] ?? 0,
                'notes' => $validated['notes'] ?? null,
                'statut' => 'brouillon',
                'created_by' => $request->user()?->id,
            ]);

            foreach ($validated['lignes'] as $ligneData) {
                $ligne = new ComClientLigne([
                    'produit_id' => $ligneData['produit_id'],
                    'quantite' => $ligneData['quantite'],
                    'prix_unitaire_ht' => $ligneData['prix_unitaire_ht'],
                    'taux_tva' => $ligneData['taux_tva'] ?? 20,
                    'remise_ligne' => $ligneData['remise_ligne'] ?? 0,
                ]);
                $ligne->calculerMontants();
                $commande->lignes()->save($ligne);
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
            return response()->json(['message' => 'Seules les commandes en brouillon peuvent être modifiées'], 422);
        }

        $validated = $request->validate([
            'date_livraison_souhaitee' => 'nullable|date',
            'remise_globale' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
            'lignes' => 'sometimes|array|min:1',
            'lignes.*.produit_id' => 'required|integer',
            'lignes.*.quantite' => 'required|integer|min:1',
            'lignes.*.prix_unitaire_ht' => 'required|numeric|min:0',
            'lignes.*.taux_tva' => 'nullable|numeric',
            'lignes.*.remise_ligne' => 'nullable|numeric|min:0|max:100',
        ]);

        DB::transaction(function () use ($commande, $validated) {
            $commande->update([
                'date_livraison_souhaitee' => $validated['date_livraison_souhaitee'] ?? $commande->date_livraison_souhaitee,
                'remise_globale' => $validated['remise_globale'] ?? $commande->remise_globale,
                'notes' => $validated['notes'] ?? $commande->notes,
            ]);

            if (isset($validated['lignes'])) {
                $commande->lignes()->delete();
                foreach ($validated['lignes'] as $ligneData) {
                    $ligne = new ComClientLigne([
                        'produit_id' => $ligneData['produit_id'],
                        'quantite' => $ligneData['quantite'],
                        'prix_unitaire_ht' => $ligneData['prix_unitaire_ht'],
                        'taux_tva' => $ligneData['taux_tva'] ?? 20,
                        'remise_ligne' => $ligneData['remise_ligne'] ?? 0,
                    ]);
                    $ligne->calculerMontants();
                    $commande->lignes()->save($ligne);
                }
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
            return response()->json(['message' => 'Cette commande ne peut pas être soumise'], 422);
        }

        $commande->statut = 'en_attente';
        $commande->save();

        return response()->json($commande);
    }

    public function accepter(Request $request, int $id): JsonResponse
    {
        $commande = ComClientEntete::with('lignes', 'client')->findOrFail($id);

        if ($commande->statut !== 'en_attente') {
            return response()->json(['message' => 'Cette commande ne peut pas être acceptée'], 422);
        }

        $client = $commande->client;
        if (! $client->peutCommander($commande->montant_ttc)) {
            return response()->json([
                'message' => 'Dépassement de l\'encours maximum autorisé',
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
                MouvementVente::creerMouvement(
                    $ligne->produit_id,
                    'reservation',
                    $ligne->quantite,
                    null, null, null, null,
                    'commande', $commande->id,
                    "Réservation pour commande {$commande->numero}",
                    $request->user()?->id
                );

                ProduitLocalisation::reserver($ligne->produit_id, $ligne->quantite, $commande->id);
            }
        });

        return response()->json($commande);
    }

    public function refuser(Request $request, int $id): JsonResponse
    {
        $commande = ComClientEntete::findOrFail($id);

        if ($commande->statut !== 'en_attente') {
            return response()->json(['message' => 'Cette commande ne peut pas être refusée'], 422);
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
            return response()->json(['message' => 'Seules les commandes en brouillon peuvent être supprimées'], 422);
        }

        $commande->delete();

        return response()->json(['message' => 'Commande supprimée']);
    }
}
