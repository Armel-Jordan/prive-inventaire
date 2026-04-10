<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BonLivraison;
use App\Models\Configuration;
use App\Models\MouvementVente;
use App\Models\Tournee;
use App\Models\TourneeBon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TourneeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Tournee::with(['camion', 'tourneeBons.bonLivraison.facture.client']);

        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }
        if ($request->has('date')) {
            $query->whereDate('date_tournee', $request->date);
        }
        if ($request->has('zone')) {
            $query->where('zone', 'like', "%{$request->zone}%");
        }

        $tournees = $query->orderBy('date_tournee', 'desc')->paginate($request->get('per_page', 20));
        return response()->json($tournees);
    }

    public function show(int $id): JsonResponse
    {
        $tournee = Tournee::with(['camion', 'tourneeBons.bonLivraison.facture.client', 'tourneeBons.bonLivraison.lignes'])->findOrFail($id);
        return response()->json($tournee);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_tournee' => 'required|date',
            'camion_id' => 'required|exists:camions,id',
            'livreur_id' => 'nullable|integer',
            'zone' => 'nullable|string|max:100',
            'heure_depart' => 'nullable|date_format:H:i',
        ]);

        $tenantId = $request->attributes->get('tenant')->id;
        $config = Configuration::pourEntite('tournee', $tenantId);
        if (!$config || !$config->auto_increment) {
            return response()->json(['success' => false, 'message' => 'Numéro requis'], 422);
        }
        $numero = $config->genererNumero();
        $config->incrementer();

        $tournee = Tournee::create([
            'numero' => $numero,
            'date_tournee' => $validated['date_tournee'],
            'camion_id' => $validated['camion_id'],
            'livreur_id' => $validated['livreur_id'] ?? null,
            'zone' => $validated['zone'] ?? null,
            'heure_depart' => $validated['heure_depart'] ?? null,
            'statut' => 'planifiee',
        ]);

        return response()->json($tournee, 201);
    }

    public function ajouterBon(Request $request, int $id): JsonResponse
    {
        $tournee = Tournee::findOrFail($id);

        if ($tournee->statut !== 'planifiee') {
            return response()->json(['message' => 'Impossible d\'ajouter des bons à cette tournée'], 422);
        }

        $validated = $request->validate([
            'bon_livraison_id' => 'required|exists:bons_livraison,id',
        ]);

        $bon = BonLivraison::findOrFail($validated['bon_livraison_id']);

        if ($bon->statut !== 'pret') {
            return response()->json(['message' => 'Le bon doit être prêt pour être ajouté à une tournée'], 422);
        }

        if ($bon->tourneeBon) {
            return response()->json(['message' => 'Ce bon est déjà assigné à une tournée'], 422);
        }

        $ordre = $tournee->tourneeBons()->max('ordre_livraison') + 1;

        TourneeBon::create([
            'tournee_id' => $tournee->id,
            'bon_livraison_id' => $bon->id,
            'ordre_livraison' => $ordre,
        ]);

        return response()->json($tournee->fresh('tourneeBons.bonLivraison'));
    }

    public function retirerBon(int $id, int $bonId): JsonResponse
    {
        $tournee = Tournee::findOrFail($id);

        if ($tournee->statut !== 'planifiee') {
            return response()->json(['message' => 'Impossible de modifier cette tournée'], 422);
        }

        TourneeBon::where('tournee_id', $id)->where('bon_livraison_id', $bonId)->delete();

        return response()->json($tournee->fresh('tourneeBons'));
    }

    public function updateOrdre(Request $request, int $id): JsonResponse
    {
        $tournee = Tournee::findOrFail($id);

        if ($tournee->statut !== 'planifiee') {
            return response()->json(['message' => 'Impossible de modifier l\'ordre'], 422);
        }

        $validated = $request->validate([
            'ordre' => 'required|array',
            'ordre.*.bon_livraison_id' => 'required|integer',
            'ordre.*.ordre_livraison' => 'required|integer|min:1',
        ]);

        DB::transaction(function () use ($tournee, $validated) {
            foreach ($validated['ordre'] as $item) {
                TourneeBon::where('tournee_id', $tournee->id)
                    ->where('bon_livraison_id', $item['bon_livraison_id'])
                    ->update(['ordre_livraison' => $item['ordre_livraison']]);
            }
        });

        return response()->json($tournee->fresh('tourneeBons'));
    }

    public function demarrer(Request $request, int $id): JsonResponse
    {
        $tournee = Tournee::with('tourneeBons.bonLivraison.lignes')->findOrFail($id);

        if ($tournee->statut !== 'planifiee') {
            return response()->json(['message' => 'Cette tournée ne peut pas être démarrée'], 422);
        }

        if ($tournee->tourneeBons->isEmpty()) {
            return response()->json(['message' => 'La tournée doit contenir au moins un bon'], 422);
        }

        DB::transaction(function () use ($tournee, $request) {
            $tournee->statut = 'en_cours';
            $tournee->heure_depart = now()->format('H:i');
            $tournee->km_depart = $request->get('km_depart');
            $tournee->save();

            foreach ($tournee->tourneeBons as $tourneeBon) {
                $bon = $tourneeBon->bonLivraison;
                $bon->statut = 'en_livraison';
                $bon->save();

                foreach ($bon->lignes as $ligne) {
                    $ligne->statut_ligne = 'charge';
                    $ligne->save();

                    MouvementVente::creerMouvement(
                        $ligne->produit_id,
                        'chargement_camion',
                        $ligne->quantite_preparee,
                        'zone_preparation', null,
                        'camion', $tournee->camion_id,
                        'tournee', $tournee->id,
                        "Chargement tournée {$tournee->numero}",
                        $request->user()?->id
                    );
                }
            }
        });

        return response()->json($tournee->fresh(['tourneeBons.bonLivraison']));
    }

    public function destroy(int $id): JsonResponse
    {
        $tournee = Tournee::findOrFail($id);

        if ($tournee->statut !== 'planifiee') {
            return response()->json(['message' => 'Seules les tournées planifiées peuvent être supprimées'], 422);
        }

        $tournee->tourneeBons()->delete();
        $tournee->delete();

        return response()->json(['message' => 'Tournée supprimée.']);
    }

    public function terminer(Request $request, int $id): JsonResponse
    {
        $tournee = Tournee::findOrFail($id);

        if ($tournee->statut !== 'en_cours') {
            return response()->json(['message' => 'Cette tournée ne peut pas être terminée'], 422);
        }

        $tournee->statut = 'terminee';
        $tournee->heure_retour = now()->format('H:i');
        $tournee->km_retour = $request->get('km_retour');
        $tournee->save();

        return response()->json($tournee);
    }
}
