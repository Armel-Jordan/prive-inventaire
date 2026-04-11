<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\ReceptionArrivagesLigne;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReceptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ReceptionArrivagesLigne::with([
            'ligneCommande.commande.fournisseur',
            'ligneCommande.produit',
            'secteur',
            'receivedBy',
        ]);

        if ($request->has('date_debut')) {
            $query->whereDate('date_reception', '>=', $request->date_debut);
        }

        if ($request->has('date_fin')) {
            $query->whereDate('date_reception', '<=', $request->date_fin);
        }

        if ($request->has('commande_id')) {
            $query->whereHas('ligneCommande', function ($q) use ($request) {
                $q->where('com_four_entete_id', $request->commande_id);
            });
        }

        $receptions = $query->orderBy('date_reception', 'desc')->paginate($request->get('per_page', 15));

        return response()->json($receptions);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'com_four_ligne_id' => 'required|exists:com_four_ligne,id',
            'date_reception' => 'required|date',
            'quantite_recue' => 'required|integer|min:1',
            'secteur_id' => 'nullable|exists:secteurs,id',
            'lot_numero' => 'nullable|string|max:50',
            'date_peremption' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $ligneCommande = ComFourLigne::findOrFail($validated['com_four_ligne_id']);

        $quantiteRestante = $ligneCommande->quantite_commandee - $ligneCommande->quantite_recue;
        if ($validated['quantite_recue'] > $quantiteRestante) {
            return response()->json([
                'message' => "La quantité reçue ({$validated['quantite_recue']}) dépasse la quantité restante ({$quantiteRestante}).",
            ], 422);
        }

        $commande = $ligneCommande->commande;
        if (! in_array($commande->statut, [ComFourEntete::STATUT_ENVOYEE, ComFourEntete::STATUT_PARTIELLE])) {
            return response()->json([
                'message' => 'Les réceptions ne sont possibles que pour les commandes envoyées ou partiellement reçues.',
            ], 422);
        }

        $reception = DB::transaction(function () use ($validated, $request) {
            return ReceptionArrivagesLigne::create([
                'com_four_ligne_id' => $validated['com_four_ligne_id'],
                'date_reception' => $validated['date_reception'],
                'quantite_recue' => $validated['quantite_recue'],
                'secteur_id' => $validated['secteur_id'] ?? null,
                'lot_numero' => $validated['lot_numero'] ?? null,
                'date_peremption' => $validated['date_peremption'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'received_by' => $request->user()->id,
            ]);
        });

        return response()->json([
            'message' => 'Réception enregistrée avec succès.',
            'reception' => $reception->load(['ligneCommande.produit', 'secteur']),
        ], 201);
    }

    public function receptionMultiple(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'commande_id' => 'required|exists:com_four_entete,id',
            'date_reception' => 'required|date',
            'receptions' => 'required|array|min:1',
            'receptions.*.com_four_ligne_id' => 'required|exists:com_four_ligne,id',
            'receptions.*.quantite_recue' => 'required|integer|min:1',
            'receptions.*.secteur_id' => 'nullable|exists:secteurs,id',
            'receptions.*.lot_numero' => 'nullable|string|max:50',
            'receptions.*.date_peremption' => 'nullable|date',
            'receptions.*.notes' => 'nullable|string',
        ]);

        $commande = ComFourEntete::findOrFail($validated['commande_id']);

        if (! in_array($commande->statut, [ComFourEntete::STATUT_ENVOYEE, ComFourEntete::STATUT_PARTIELLE])) {
            return response()->json([
                'message' => 'Les réceptions ne sont possibles que pour les commandes envoyées ou partiellement reçues.',
            ], 422);
        }

        $receptions = DB::transaction(function () use ($validated, $request) {
            $created = [];

            foreach ($validated['receptions'] as $receptionData) {
                $ligneCommande = ComFourLigne::findOrFail($receptionData['com_four_ligne_id']);
                $quantiteRestante = $ligneCommande->quantite_commandee - $ligneCommande->quantite_recue;

                if ($receptionData['quantite_recue'] > $quantiteRestante) {
                    throw new \Exception("Quantité reçue dépasse la quantité restante pour le produit {$ligneCommande->produit->description}");
                }

                $created[] = ReceptionArrivagesLigne::create([
                    'com_four_ligne_id' => $receptionData['com_four_ligne_id'],
                    'date_reception' => $validated['date_reception'],
                    'quantite_recue' => $receptionData['quantite_recue'],
                    'secteur_id' => $receptionData['secteur_id'] ?? null,
                    'lot_numero' => $receptionData['lot_numero'] ?? null,
                    'date_peremption' => $receptionData['date_peremption'] ?? null,
                    'notes' => $receptionData['notes'] ?? null,
                    'received_by' => $request->user()->id,
                ]);
            }

            return $created;
        });

        return response()->json([
            'message' => count($receptions).' réception(s) enregistrée(s) avec succès.',
            'receptions' => $receptions,
        ], 201);
    }

    public function commandesEnAttente(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $commandes = ComFourEntete::with(['fournisseur', 'lignes.produit'])
            ->where('tenant_id', $tenantId)
            ->whereIn('statut', [ComFourEntete::STATUT_ENVOYEE, ComFourEntete::STATUT_PARTIELLE])
            ->orderBy('date_livraison_prevue')
            ->get();

        return response()->json($commandes);
    }

    public function lignesEnAttente(ComFourEntete $commande): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        if ($commande->tenant_id !== $tenantId) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $lignes = $commande->lignes()
            ->with('produit')
            ->whereRaw('quantite_recue < quantite_commandee')
            ->get()
            ->map(function ($ligne) {
                $ligne->quantite_restante = $ligne->quantite_commandee - $ligne->quantite_recue;

                return $ligne;
            });

        return response()->json($lignes);
    }
}
