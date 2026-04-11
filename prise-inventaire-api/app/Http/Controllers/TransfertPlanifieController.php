<?php

namespace App\Http\Controllers;

use App\Models\MouvementTenant;
use App\Models\TransfertPlanifie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransfertPlanifieController extends Controller
{
    /**
     * Liste des transferts planifiés — isolés par tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $query = TransfertPlanifie::where('tenant_id', $tenantId)->orderBy('date_planifiee', 'asc');

        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->filled('date_debut')) {
            $query->whereDate('date_planifiee', '>=', $request->date_debut);
        }

        if ($request->filled('date_fin')) {
            $query->whereDate('date_planifiee', '<=', $request->date_fin);
        }

        return response()->json($query->get());
    }

    /**
     * Créer un transfert planifié.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

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
        $validated['tenant_id'] = $tenantId;
        $validated['cree_par'] = $request->user()->name ?? 'Admin';

        $transfert = TransfertPlanifie::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Transfert planifié créé',
            'transfert' => $transfert,
        ], 201);
    }

    /**
     * Détails d'un transfert planifié — vérifie l'appartenance au tenant.
     */
    public function show(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $transfert = TransfertPlanifie::where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json($transfert);
    }

    /**
     * Modifier un transfert planifié.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $transfert = TransfertPlanifie::where('tenant_id', $tenantId)->findOrFail($id);

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
     * Exécuter un transfert planifié.
     */
    public function execute(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $transfert = TransfertPlanifie::where('tenant_id', $tenantId)->findOrFail($id);

        if ($transfert->statut !== 'planifie') {
            return response()->json([
                'success' => false,
                'message' => 'Ce transfert ne peut pas être exécuté',
            ], 422);
        }

        MouvementTenant::create([
            'tenant_id' => $tenantId,
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
     * Annuler un transfert planifié.
     */
    public function cancel(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $transfert = TransfertPlanifie::where('tenant_id', $tenantId)->findOrFail($id);

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
     * Supprimer un transfert planifié.
     */
    public function destroy(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $transfert = TransfertPlanifie::where('tenant_id', $tenantId)->findOrFail($id);

        if ($transfert->statut === 'execute') {
            return response()->json([
                'success' => false,
                'message' => 'Un transfert exécuté ne peut pas être supprimé',
            ], 422);
        }

        $transfert->delete();

        return response()->json(['success' => true, 'message' => 'Transfert supprimé']);
    }

    /**
     * Transferts à venir (aujourd'hui et demain) — isolés par tenant.
     */
    public function upcoming(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $transferts = TransfertPlanifie::where('tenant_id', $tenantId)
            ->where('statut', 'planifie')
            ->whereDate('date_planifiee', '<=', now()->addDays(2))
            ->orderBy('date_planifiee', 'asc')
            ->get();

        return response()->json($transferts);
    }

    /**
     * Statistiques des transferts planifiés — isolées par tenant.
     */
    public function stats(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $base = fn () => TransfertPlanifie::where('tenant_id', $tenantId);

        return response()->json([
            'planifies' => $base()->where('statut', 'planifie')->count(),
            'executes_ce_mois' => $base()->where('statut', 'execute')->whereMonth('execute_le', now()->month)->count(),
            'annules_ce_mois' => $base()->where('statut', 'annule')->whereMonth('updated_at', now()->month)->count(),
            'a_venir_24h' => $base()->where('statut', 'planifie')->where('date_planifiee', '<=', now()->addDay())->count(),
        ]);
    }
}
