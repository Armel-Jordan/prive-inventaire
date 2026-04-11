<?php

namespace App\Http\Controllers;

use App\Models\MouvementTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MouvementTenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $query = MouvementTenant::where('tenant_id', $tenantId);

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('produit_numero')) {
            $query->where('produit_numero', $request->produit_numero);
        }

        if ($request->has('secteur')) {
            $query->where(function ($q) use ($request) {
                $q->where('secteur_source', $request->secteur)
                    ->orWhere('secteur_destination', $request->secteur);
            });
        }

        if ($request->has('date_debut')) {
            $query->whereDate('date_mouvement', '>=', $request->date_debut);
        }

        if ($request->has('date_fin')) {
            $query->whereDate('date_mouvement', '<=', $request->date_fin);
        }

        $mouvements = $query->orderByDesc('date_mouvement')
            ->limit($request->get('limit', 100))
            ->get();

        return response()->json($mouvements);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|in:arrivage,transfert,sortie,ajustement',
            'produit_numero' => 'required|string|max:50',
            'produit_nom' => 'nullable|string|max:255',
            'secteur_source' => 'nullable|string|max:100',
            'secteur_destination' => 'nullable|string|max:100',
            'quantite' => 'required|numeric|min:0.0001',
            'unite_mesure' => 'nullable|string|max:20',
            'motif' => 'nullable|string|max:255',
            'employe' => 'required|string|max:100',
        ]);

        // Validation selon le type
        if ($validated['type'] === 'transfert') {
            if (empty($validated['secteur_source']) || empty($validated['secteur_destination'])) {
                return response()->json([
                    'message' => 'Un transfert nécessite un secteur source et destination',
                ], 422);
            }
            if ($validated['secteur_source'] === $validated['secteur_destination']) {
                return response()->json([
                    'message' => 'Le secteur source et destination doivent être différents',
                ], 422);
            }
        }

        if ($validated['type'] === 'arrivage' && empty($validated['secteur_destination'])) {
            return response()->json([
                'message' => 'Un arrivage nécessite un secteur de destination',
            ], 422);
        }

        if ($validated['type'] === 'sortie' && empty($validated['secteur_source'])) {
            return response()->json([
                'message' => 'Une sortie nécessite un secteur source',
            ], 422);
        }

        $mouvement = MouvementTenant::create([
            ...$validated,
            'tenant_id' => auth()->user()->tenant_id,
            'date_mouvement' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Mouvement enregistré avec succès',
            'mouvement' => $mouvement,
        ], 201);
    }

    public function show($id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $mouvement = MouvementTenant::where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json($mouvement);
    }

    /**
     * Relocalisation par lot - déplacer plusieurs produits d'un secteur à un autre
     */
    public function relocalisationParSecteur(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'secteur_source' => 'required|string|max:100',
            'secteur_destination' => 'required|string|max:100',
            'produits' => 'required|array|min:1',
            'produits.*.numero' => 'required|string',
            'produits.*.nom' => 'nullable|string',
            'produits.*.quantite' => 'required|numeric|min:0.0001',
            'produits.*.unite_mesure' => 'nullable|string',
            'motif' => 'nullable|string|max:255',
            'employe' => 'required|string|max:100',
        ]);

        if ($validated['secteur_source'] === $validated['secteur_destination']) {
            return response()->json([
                'message' => 'Le secteur source et destination doivent être différents',
            ], 422);
        }

        $tenantId = auth()->user()->tenant_id;
        $mouvements = [];

        DB::transaction(function () use ($validated, $tenantId, &$mouvements) {
            foreach ($validated['produits'] as $produit) {
                $mouvement = MouvementTenant::create([
                    'tenant_id' => $tenantId,
                    'type' => 'transfert',
                    'produit_numero' => $produit['numero'],
                    'produit_nom' => $produit['nom'] ?? null,
                    'secteur_source' => $validated['secteur_source'],
                    'secteur_destination' => $validated['secteur_destination'],
                    'quantite' => $produit['quantite'],
                    'unite_mesure' => $produit['unite_mesure'] ?? null,
                    'motif' => $validated['motif'] ?? 'Relocalisation par secteur',
                    'employe' => $validated['employe'],
                    'date_mouvement' => now(),
                ]);
                $mouvements[] = $mouvement;
            }
        });

        return response()->json([
            'success' => true,
            'message' => count($mouvements).' produit(s) relocalisé(s) avec succès',
            'mouvements' => $mouvements,
        ], 201);
    }

    /**
     * Arrivage en lot - ajouter plusieurs produits dans un secteur
     */
    public function arrivageLot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'secteur_destination' => 'required|string|max:100',
            'produits' => 'required|array|min:1',
            'produits.*.numero' => 'required|string',
            'produits.*.nom' => 'nullable|string',
            'produits.*.quantite' => 'required|numeric|min:0.0001',
            'produits.*.unite_mesure' => 'nullable|string',
            'motif' => 'nullable|string|max:255',
            'employe' => 'required|string|max:100',
        ]);

        $tenantId = auth()->user()->tenant_id;
        $mouvements = [];

        DB::transaction(function () use ($validated, $tenantId, &$mouvements) {
            foreach ($validated['produits'] as $produit) {
                $mouvement = MouvementTenant::create([
                    'tenant_id' => $tenantId,
                    'type' => 'arrivage',
                    'produit_numero' => $produit['numero'],
                    'produit_nom' => $produit['nom'] ?? null,
                    'secteur_source' => null,
                    'secteur_destination' => $validated['secteur_destination'],
                    'quantite' => $produit['quantite'],
                    'unite_mesure' => $produit['unite_mesure'] ?? null,
                    'motif' => $validated['motif'] ?? 'Arrivage',
                    'employe' => $validated['employe'],
                    'date_mouvement' => now(),
                ]);
                $mouvements[] = $mouvement;
            }
        });

        return response()->json([
            'success' => true,
            'message' => count($mouvements).' produit(s) ajouté(s) avec succès',
            'mouvements' => $mouvements,
        ], 201);
    }

    /**
     * Historique des mouvements d'un produit
     */
    public function historyByProduit($numero): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $mouvements = MouvementTenant::where('tenant_id', $tenantId)
            ->where('produit_numero', $numero)
            ->orderByDesc('date_mouvement')
            ->get();

        return response()->json($mouvements);
    }

    /**
     * Historique des mouvements d'un secteur
     */
    public function historyBySecteur($secteur): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $mouvements = MouvementTenant::where('tenant_id', $tenantId)
            ->where(function ($q) use ($secteur) {
                $q->where('secteur_source', $secteur)
                    ->orWhere('secteur_destination', $secteur);
            })
            ->orderByDesc('date_mouvement')
            ->get();

        return response()->json($mouvements);
    }

    /**
     * Statistiques des mouvements
     */
    public function stats(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $today = now()->toDateString();
        $thisMonth = now()->startOfMonth()->toDateString();

        $stats = [
            'total' => MouvementTenant::where('tenant_id', $tenantId)->count(),
            'today' => MouvementTenant::where('tenant_id', $tenantId)->whereDate('date_mouvement', $today)->count(),
            'this_month' => MouvementTenant::where('tenant_id', $tenantId)->whereDate('date_mouvement', '>=', $thisMonth)->count(),
            'by_type' => MouvementTenant::where('tenant_id', $tenantId)
                ->selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type'),
        ];

        return response()->json($stats);
    }
}
