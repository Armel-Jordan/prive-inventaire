<?php

namespace App\Http\Controllers;

use App\Models\Approbation;
use App\Models\MouvementTenant;
use App\Support\SeuilsApprobation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApprobationController extends Controller
{
    /**
     * Liste des demandes d'approbation — isolées par tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $query = Approbation::where('tenant_id', $tenantId)->orderByDesc('created_at');

        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->filled('demandeur')) {
            $query->where('demandeur', $request->demandeur);
        }

        return response()->json($query->get());
    }

    /**
     * Créer une demande d'approbation.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

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

        $approbation = SeuilsApprobation::soumettre(
            ['type' => $validated['type_mouvement'], ...$validated], $tenantId, $validated['demandeur']
        );
        if ($approbation) {
            return response()->json([
                'success' => true,
                'requires_approval' => true,
                'message' => __('Quantité >= :seuil, approbation requise', ['seuil' => (float) $approbation->seuil_declenchement]),
                'approbation' => $approbation,
            ], 201);
        }

        // Champs facultatifs absents de la requête : ils ne figurent pas dans $validated (l'accès direct échouait).
        $mouvement = MouvementTenant::create([
            'tenant_id' => $tenantId,
            'type' => $validated['type_mouvement'],
            'produit_numero' => $validated['produit_numero'],
            'produit_nom' => $validated['produit_nom'] ?? null,
            'secteur_source' => $validated['secteur_source'] ?? null,
            'secteur_destination' => $validated['secteur_destination'] ?? null,
            'quantite' => $validated['quantite'],
            'unite_mesure' => $validated['unite_mesure'] ?? null,
            'motif' => $validated['motif'] ?? null,
            'employe' => $validated['demandeur'],
            // Colonne obligatoire : sans elle, l'insertion échouait.
            'date_mouvement' => now(),
        ]);

        return response()->json([
            'success' => true,
            'requires_approval' => false,
            'message' => __('Mouvement créé directement'),
            'mouvement' => $mouvement,
        ], 201);
    }

    /**
     * Approuver une demande — vérifie l'appartenance au tenant.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $approbation = Approbation::where('tenant_id', $tenantId)->findOrFail($id);

        if ($approbation->statut !== 'en_attente') {
            return response()->json([
                'success' => false,
                'message' => __('Cette demande a déjà été traitée'),
            ], 422);
        }

        // L'approbateur est le compte connecté : le nom saisi par l'écran (« Admin Web ») était libre, donc usurpable.
        $validated = $request->validate([
            'commentaire' => 'nullable|string',
        ]);

        MouvementTenant::create([
            'tenant_id' => $tenantId,
            'type' => $approbation->type_mouvement,
            'produit_numero' => $approbation->produit_numero,
            'produit_nom' => $approbation->produit_nom,
            'secteur_source' => $approbation->secteur_source,
            'secteur_destination' => $approbation->secteur_destination,
            'quantite' => $approbation->quantite,
            'unite_mesure' => $approbation->unite_mesure,
            'motif' => $approbation->motif,
            'employe' => $approbation->demandeur,
            // Colonne obligatoire : sans elle, l'approbation échouait.
            'date_mouvement' => now(),
        ]);

        $approbation->update([
            'statut' => 'approuve',
            'approbateur' => $this->approbateur($request),
            'date_decision' => now(),
            'commentaire_approbateur' => $validated['commentaire'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('Demande approuvée et mouvement créé'),
            'approbation' => $approbation->fresh(),
        ]);
    }

    /**
     * Rejeter une demande — vérifie l'appartenance au tenant.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $approbation = Approbation::where('tenant_id', $tenantId)->findOrFail($id);

        if ($approbation->statut !== 'en_attente') {
            return response()->json([
                'success' => false,
                'message' => __('Cette demande a déjà été traitée'),
            ], 422);
        }

        $validated = $request->validate([
            'commentaire' => 'required|string',
        ]);

        $approbation->update([
            'statut' => 'rejete',
            'approbateur' => $this->approbateur($request),
            'date_decision' => now(),
            'commentaire_approbateur' => $validated['commentaire'],
        ]);

        return response()->json([
            'success' => true,
            'message' => __('Demande rejetée'),
            'approbation' => $approbation->fresh(),
        ]);
    }

    /**
     * Statistiques des approbations — isolées par tenant.
     */
    public function stats(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        return response()->json([
            'en_attente' => Approbation::where('tenant_id', $tenantId)->where('statut', 'en_attente')->count(),
            'approuvees_ce_mois' => Approbation::where('tenant_id', $tenantId)->where('statut', 'approuve')
                ->whereMonth('date_decision', now()->month)->count(),
            'rejetees_ce_mois' => Approbation::where('tenant_id', $tenantId)->where('statut', 'rejete')
                ->whereMonth('date_decision', now()->month)->count(),
        ]);
    }

    /**
     * Obtenir les seuils d'approbation du tenant.
     */
    public function getSettings(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $seuils = DB::table('parametres_approbation')->where('tenant_id', $tenantId)->get();

        if ($seuils->isEmpty()) {
            // Seuils proposés, inactifs : ils s'appliquent désormais aux mouvements (Relocalisation, mobile),
            // l'entreprise choisit de les activer (Approbations › Paramètres).
            $defaults = [
                ['type_mouvement' => 'arrivage', 'seuil_quantite' => 100, 'actif' => false],
                ['type_mouvement' => 'transfert', 'seuil_quantite' => 50, 'actif' => false],
                ['type_mouvement' => 'sortie', 'seuil_quantite' => 50, 'actif' => false],
            ];
            foreach ($defaults as $default) {
                DB::table('parametres_approbation')->insert([
                    ...$default,
                    'tenant_id' => $tenantId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $seuils = DB::table('parametres_approbation')->where('tenant_id', $tenantId)->get();
        }

        return response()->json($seuils);
    }

    /**
     * Mettre à jour les seuils d'approbation du tenant.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $validated = $request->validate([
            'seuils' => 'required|array',
            'seuils.*.type_mouvement' => 'required|in:arrivage,transfert,sortie',
            'seuils.*.seuil_quantite' => 'required|numeric|min:0',
            'seuils.*.actif' => 'required|boolean',
        ]);

        foreach ($validated['seuils'] as $seuil) {
            DB::table('parametres_approbation')
                ->updateOrInsert(
                    ['type_mouvement' => $seuil['type_mouvement'], 'tenant_id' => $tenantId],
                    [
                        'seuil_quantite' => $seuil['seuil_quantite'],
                        'actif' => $seuil['actif'],
                        'updated_at' => now(),
                    ]
                );
        }

        return response()->json(['success' => true, 'message' => __('Seuils mis à jour')]);
    }

    /** Nom de l'utilisateur connecté qui décide (son courriel s'il n'a pas de nom). */
    private function approbateur(Request $request): string
    {
        return $request->user()->nom ?: $request->user()->email;
    }
}
