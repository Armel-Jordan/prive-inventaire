<?php

namespace App\Http\Controllers;

use App\Models\Approbation;
use App\Models\MouvementTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApprobationController extends Controller
{
    /**
     * Liste des demandes d'approbation
     */
    public function index(Request $request): JsonResponse
    {
        $query = Approbation::query()->orderByDesc('created_at');

        if ($request->has('statut') && $request->statut) {
            $query->where('statut', $request->statut);
        }

        if ($request->has('demandeur') && $request->demandeur) {
            $query->where('demandeur', $request->demandeur);
        }

        $approbations = $query->get();

        return response()->json($approbations);
    }

    /**
     * Créer une demande d'approbation
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type_mouvement' => 'required|in:arrivage,transfert,sortie',
            'produit_numero' => 'required|string|max:50',
            'produit_nom' => 'nullable|string|max:200',
            'secteur_source' => 'nullable|string|max:50',
            'secteur_destination' => 'nullable|string|max:50',
            'quantite' => 'required|numeric|min:0.001',
            'unite_mesure' => 'nullable|string|max:20',
            'motif' => 'nullable|string|max:255',
            'demandeur' => 'required|string|max:100',
        ]);

        // Vérifier si approbation nécessaire
        $seuil = $this->getSeuilApprobation($validated['type_mouvement']);

        if ($seuil && $validated['quantite'] >= $seuil) {
            $validated['statut'] = 'en_attente';
            $validated['seuil_declenchement'] = $seuil;
            $approbation = Approbation::create($validated);

            return response()->json([
                'success' => true,
                'requires_approval' => true,
                'message' => "Quantité >= {$seuil}, approbation requise",
                'approbation' => $approbation,
            ], 201);
        }

        // Pas besoin d'approbation, créer directement le mouvement
        $mouvement = MouvementTenant::create([
            'type' => $validated['type_mouvement'],
            'produit_numero' => $validated['produit_numero'],
            'produit_nom' => $validated['produit_nom'],
            'secteur_source' => $validated['secteur_source'],
            'secteur_destination' => $validated['secteur_destination'],
            'quantite' => $validated['quantite'],
            'unite_mesure' => $validated['unite_mesure'],
            'motif' => $validated['motif'],
            'employe' => $validated['demandeur'],
        ]);

        return response()->json([
            'success' => true,
            'requires_approval' => false,
            'message' => 'Mouvement créé directement',
            'mouvement' => $mouvement,
        ], 201);
    }

    /**
     * Approuver une demande
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $approbation = Approbation::findOrFail($id);

        if ($approbation->statut !== 'en_attente') {
            return response()->json([
                'success' => false,
                'message' => 'Cette demande a déjà été traitée',
            ], 422);
        }

        $validated = $request->validate([
            'approbateur' => 'required|string|max:100',
            'commentaire' => 'nullable|string',
        ]);

        // Créer le mouvement
        MouvementTenant::create([
            'type' => $approbation->type_mouvement,
            'produit_numero' => $approbation->produit_numero,
            'produit_nom' => $approbation->produit_nom,
            'secteur_source' => $approbation->secteur_source,
            'secteur_destination' => $approbation->secteur_destination,
            'quantite' => $approbation->quantite,
            'unite_mesure' => $approbation->unite_mesure,
            'motif' => $approbation->motif,
            'employe' => $approbation->demandeur,
        ]);

        // Mettre à jour l'approbation
        $approbation->update([
            'statut' => 'approuve',
            'approbateur' => $validated['approbateur'],
            'date_decision' => now(),
            'commentaire_approbateur' => $validated['commentaire'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Demande approuvée et mouvement créé',
            'approbation' => $approbation->fresh(),
        ]);
    }

    /**
     * Rejeter une demande
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $approbation = Approbation::findOrFail($id);

        if ($approbation->statut !== 'en_attente') {
            return response()->json([
                'success' => false,
                'message' => 'Cette demande a déjà été traitée',
            ], 422);
        }

        $validated = $request->validate([
            'approbateur' => 'required|string|max:100',
            'commentaire' => 'required|string',
        ]);

        $approbation->update([
            'statut' => 'rejete',
            'approbateur' => $validated['approbateur'],
            'date_decision' => now(),
            'commentaire_approbateur' => $validated['commentaire'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Demande rejetée',
            'approbation' => $approbation->fresh(),
        ]);
    }

    /**
     * Statistiques des approbations
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'en_attente' => Approbation::where('statut', 'en_attente')->count(),
            'approuvees_ce_mois' => Approbation::where('statut', 'approuve')
                ->whereMonth('date_decision', now()->month)
                ->count(),
            'rejetees_ce_mois' => Approbation::where('statut', 'rejete')
                ->whereMonth('date_decision', now()->month)
                ->count(),
        ]);
    }

    /**
     * Obtenir les seuils d'approbation
     */
    public function getSettings(): JsonResponse
    {
        $seuils = DB::table('parametres_approbation')->get();

        if ($seuils->isEmpty()) {
            // Créer les seuils par défaut
            $defaults = [
                ['type_mouvement' => 'arrivage', 'seuil_quantite' => 100, 'actif' => true],
                ['type_mouvement' => 'transfert', 'seuil_quantite' => 50, 'actif' => true],
                ['type_mouvement' => 'sortie', 'seuil_quantite' => 50, 'actif' => true],
            ];
            foreach ($defaults as $default) {
                DB::table('parametres_approbation')->insert([
                    ...$default,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $seuils = DB::table('parametres_approbation')->get();
        }

        return response()->json($seuils);
    }

    /**
     * Mettre à jour les seuils d'approbation
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'seuils' => 'required|array',
            'seuils.*.type_mouvement' => 'required|in:arrivage,transfert,sortie',
            'seuils.*.seuil_quantite' => 'required|numeric|min:0',
            'seuils.*.actif' => 'required|boolean',
        ]);

        foreach ($validated['seuils'] as $seuil) {
            DB::table('parametres_approbation')
                ->updateOrInsert(
                    ['type_mouvement' => $seuil['type_mouvement']],
                    [
                        'seuil_quantite' => $seuil['seuil_quantite'],
                        'actif' => $seuil['actif'],
                        'updated_at' => now(),
                    ]
                );
        }

        return response()->json([
            'success' => true,
            'message' => 'Seuils mis à jour',
        ]);
    }

    private function getSeuilApprobation(string $type): ?float
    {
        $param = DB::table('parametres_approbation')
            ->where('type_mouvement', $type)
            ->where('actif', true)
            ->first();

        return $param ? (float) $param->seuil_quantite : null;
    }
}
