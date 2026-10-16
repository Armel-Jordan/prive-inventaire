<?php

namespace App\Http\Controllers;

use App\Models\MouvementTenant;
use App\Support\SeuilsApprobation;
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

        $query->orderByDesc('date_mouvement')->orderByDesc('id');

        // Avec « page » : tout l'historique, page par page (l'écran s'arrêtait aux 100 derniers mouvements).
        // Sans : la liste limitée attendue par l'application mobile.
        if ($request->has('page')) {
            $request->validate(['per_page' => 'nullable|integer|min:1|max:500']);

            return response()->json($query->paginate((int) $request->input('per_page', 50)));
        }

        $mouvements = $query->limit($request->get('limit', 100))->get();

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

        // Quantité au-delà du seuil d'approbation de son type : demande d'approbation, mouvement différé.
        $approbation = SeuilsApprobation::soumettre($validated, auth()->user()->tenant_id, $validated['employe']);
        if ($approbation) {
            return response()->json([
                'success' => true,
                'requires_approval' => true,
                'message' => 'Quantité supérieure ou égale au seuil ('.(float) $approbation->seuil_declenchement.') : le mouvement attend une approbation.',
                'approbation' => $approbation,
            ], 202);
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
        [$mouvements, $approbations] = $this->enregistrerLot($validated, $tenantId, fn (array $produit) => [
            'type' => 'transfert',
            'secteur_source' => $validated['secteur_source'],
            'secteur_destination' => $validated['secteur_destination'],
            'motif' => $validated['motif'] ?? 'Relocalisation par secteur',
        ]);

        return response()->json([
            'success' => true,
            'message' => $this->messageLot(count($mouvements), count($approbations), 'relocalisé(s)'),
            'mouvements' => $mouvements,
            'approbations' => $approbations,
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
        [$mouvements, $approbations] = $this->enregistrerLot($validated, $tenantId, fn (array $produit) => [
            'type' => 'arrivage',
            'secteur_source' => null,
            'secteur_destination' => $validated['secteur_destination'],
            'motif' => $validated['motif'] ?? 'Arrivage',
        ]);

        return response()->json([
            'success' => true,
            'message' => $this->messageLot(count($mouvements), count($approbations), 'ajouté(s)'),
            'mouvements' => $mouvements,
            'approbations' => $approbations,
        ], 201);
    }

    /**
     * Mouvements d'un lot : chaque ligne qui atteint le seuil d'approbation de son type devient une demande
     * d'approbation, les autres sont enregistrées.
     *
     * @return array{0: array<int, MouvementTenant>, 1: array<int, \App\Models\Approbation>}
     */
    private function enregistrerLot(array $validated, int $tenantId, callable $champsDuLot): array
    {
        $mouvements = [];
        $approbations = [];

        DB::transaction(function () use ($validated, $tenantId, $champsDuLot, &$mouvements, &$approbations) {
            foreach ($validated['produits'] as $produit) {
                $ligne = [
                    ...$champsDuLot($produit),
                    'produit_numero' => $produit['numero'],
                    'produit_nom' => $produit['nom'] ?? null,
                    'quantite' => $produit['quantite'],
                    'unite_mesure' => $produit['unite_mesure'] ?? null,
                ];

                $approbation = SeuilsApprobation::soumettre($ligne, $tenantId, $validated['employe']);
                if ($approbation) {
                    $approbations[] = $approbation;

                    continue;
                }

                $mouvements[] = MouvementTenant::create([
                    ...$ligne,
                    'tenant_id' => $tenantId,
                    'employe' => $validated['employe'],
                    'date_mouvement' => now(),
                ]);
            }
        });

        return [$mouvements, $approbations];
    }

    private function messageLot(int $enregistres, int $enAttente, string $participe): string
    {
        $message = "{$enregistres} produit(s) {$participe} avec succès";

        return $enAttente > 0 ? "{$message} ; {$enAttente} en attente d'approbation (seuil atteint)" : $message;
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
