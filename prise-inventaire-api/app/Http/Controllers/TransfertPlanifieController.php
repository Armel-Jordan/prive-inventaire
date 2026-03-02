<?php

namespace App\Http\Controllers;

use App\Models\TransfertPlanifie;
use App\Models\MouvementTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransfertPlanifieController extends Controller
{
    /**
     * Liste des transferts planifiés
     */
    public function index(Request $request): JsonResponse
    {
        $query = TransfertPlanifie::query()->orderBy('date_planifiee', 'asc');

        if ($request->has('statut') && $request->statut) {
            $query->where('statut', $request->statut);
        }

        if ($request->has('date_debut') && $request->date_debut) {
            $query->whereDate('date_planifiee', '>=', $request->date_debut);
        }

        if ($request->has('date_fin') && $request->date_fin) {
            $query->whereDate('date_planifiee', '<=', $request->date_fin);
        }

        $transferts = $query->get();

        return response()->json($transferts);
    }

    /**
     * Créer un transfert planifié
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|in:arrivage,transfert,sortie',
            'produit_numero' => 'required|string|max:50',
            'produit_nom' => 'nullable|string|max:200',
            'secteur_source' => 'nullable|string|max:50',
            'secteur_destination' => 'nullable|string|max:50',
            'quantite' => 'required|numeric|min:0.001',
            'unite_mesure' => 'nullable|string|max:20',
            'motif' => 'nullable|string|max:255',
            'employe' => 'required|string|max:100',
            'date_planifiee' => 'required|date|after:now',
            'notes' => 'nullable|string',
        ]);

        $validated['statut'] = 'planifie';
        $validated['cree_par'] = $request->user()->name ?? 'Admin';

        $transfert = TransfertPlanifie::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Transfert planifié créé',
            'transfert' => $transfert,
        ], 201);
    }

    /**
     * Détails d'un transfert planifié
     */
    public function show(int $id): JsonResponse
    {
        $transfert = TransfertPlanifie::findOrFail($id);
        return response()->json($transfert);
    }

    /**
     * Modifier un transfert planifié
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $transfert = TransfertPlanifie::findOrFail($id);

        if ($transfert->statut !== 'planifie') {
            return response()->json([
                'success' => false,
                'message' => 'Seuls les transferts planifiés peuvent être modifiés',
            ], 422);
        }

        $validated = $request->validate([
            'type' => 'sometimes|in:arrivage,transfert,sortie',
            'produit_numero' => 'sometimes|string|max:50',
            'produit_nom' => 'nullable|string|max:200',
            'secteur_source' => 'nullable|string|max:50',
            'secteur_destination' => 'nullable|string|max:50',
            'quantite' => 'sometimes|numeric|min:0.001',
            'unite_mesure' => 'nullable|string|max:20',
            'motif' => 'nullable|string|max:255',
            'employe' => 'sometimes|string|max:100',
            'date_planifiee' => 'sometimes|date|after:now',
            'notes' => 'nullable|string',
        ]);

        $transfert->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Transfert planifié mis à jour',
            'transfert' => $transfert->fresh(),
        ]);
    }

    /**
     * Exécuter un transfert planifié
     */
    public function execute(Request $request, int $id): JsonResponse
    {
        $transfert = TransfertPlanifie::findOrFail($id);

        if ($transfert->statut !== 'planifie') {
            return response()->json([
                'success' => false,
                'message' => 'Ce transfert ne peut pas être exécuté',
            ], 422);
        }

        // Créer le mouvement réel
        MouvementTenant::create([
            'type' => $transfert->type,
            'produit_numero' => $transfert->produit_numero,
            'produit_nom' => $transfert->produit_nom,
            'secteur_source' => $transfert->secteur_source,
            'secteur_destination' => $transfert->secteur_destination,
            'quantite' => $transfert->quantite,
            'unite_mesure' => $transfert->unite_mesure,
            'motif' => $transfert->motif,
            'employe' => $transfert->employe,
        ]);

        // Marquer comme exécuté
        $transfert->update([
            'statut' => 'execute',
            'execute_le' => now(),
            'execute_par' => $request->user()->name ?? 'Admin',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Transfert exécuté avec succès',
            'transfert' => $transfert->fresh(),
        ]);
    }

    /**
     * Annuler un transfert planifié
     */
    public function cancel(int $id): JsonResponse
    {
        $transfert = TransfertPlanifie::findOrFail($id);

        if ($transfert->statut !== 'planifie') {
            return response()->json([
                'success' => false,
                'message' => 'Ce transfert ne peut pas être annulé',
            ], 422);
        }

        $transfert->update(['statut' => 'annule']);

        return response()->json([
            'success' => true,
            'message' => 'Transfert annulé',
            'transfert' => $transfert->fresh(),
        ]);
    }

    /**
     * Supprimer un transfert planifié
     */
    public function destroy(int $id): JsonResponse
    {
        $transfert = TransfertPlanifie::findOrFail($id);

        if ($transfert->statut === 'execute') {
            return response()->json([
                'success' => false,
                'message' => 'Un transfert exécuté ne peut pas être supprimé',
            ], 422);
        }

        $transfert->delete();

        return response()->json([
            'success' => true,
            'message' => 'Transfert supprimé',
        ]);
    }

    /**
     * Transferts à venir (aujourd'hui et demain)
     */
    public function upcoming(): JsonResponse
    {
        $transferts = TransfertPlanifie::where('statut', 'planifie')
            ->whereDate('date_planifiee', '<=', now()->addDays(2))
            ->orderBy('date_planifiee', 'asc')
            ->get();

        return response()->json($transferts);
    }

    /**
     * Statistiques des transferts planifiés
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'planifies' => TransfertPlanifie::where('statut', 'planifie')->count(),
            'executes_ce_mois' => TransfertPlanifie::where('statut', 'execute')
                ->whereMonth('execute_le', now()->month)
                ->count(),
            'annules_ce_mois' => TransfertPlanifie::where('statut', 'annule')
                ->whereMonth('updated_at', now()->month)
                ->count(),
            'a_venir_24h' => TransfertPlanifie::where('statut', 'planifie')
                ->where('date_planifiee', '<=', now()->addDay())
                ->count(),
        ]);
    }
}
