<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BonLivraison;
use App\Models\Configuration;
use App\Models\MouvementVente;
use App\Models\Tournee;
use App\Models\TourneeBon;
use App\Services\ChargementsTournee;
use App\Services\FeuilleDeRoutePdf;
use App\Services\MatieresDangereusesPdf;
use App\Services\SequencesLivraison;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class TourneeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $query = Tournee::with(['camion', 'tourneeBons.bonLivraison.facture.client'])->where('tenant_id', $tenantId);

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
        return response()->json($this->detail(Tournee::findOrFail($id)));
    }

    /**
     * Tournée avec ses bons, leur facture et leur client : ce que l'écran affiche. Après un ajout ou un retrait,
     * la réponse ne portait que les liens tournée-bon, et la fiche ouverte perdait les numéros et les clients.
     */
    private function detail(Tournee $tournee): Tournee
    {
        $tournee = $tournee->fresh(['camion', 'itineraire:id,code,nom', 'tourneeBons.bonLivraison.facture.client', 'tourneeBons.bonLivraison.lignes.produit']);
        // Charge estimée (poids des produits encore à livrer) et matières dangereuses à bord.
        $poids = 0.0;
        $dangereuses = 0;
        foreach ($tournee->tourneeBons as $lien) {
            if (! $lien->bonLivraison || $lien->bonLivraison->statut === 'annule') {
                continue;
            }
            foreach ($lien->bonLivraison->lignes as $ligne) {
                $quantite = max(0, (int) ($ligne->quantite_preparee ?: $ligne->quantite_a_livrer) - (int) $ligne->quantite_livree);
                $poids += $quantite * (float) ($ligne->produit?->poids_kg ?? 0);
                $dangereuses += $quantite > 0 && $ligne->produit?->estDangereux() ? 1 : 0;
            }
        }

        return $tournee->setAttribute('poids_total_kg', round($poids, 2))->setAttribute('matieres_dangereuses', $dangereuses)
            // Avancement du chargement du camion (colis et vrac cochés).
            ->setAttribute('chargement', app(ChargementsTournee::class)->etat($tournee)['resume']);
    }

    /** Range les arrêts de la tournée planifiée par rang de passage des clients. */
    public function ordonner(int $id, SequencesLivraison $sequences): JsonResponse
    {
        $tournee = Tournee::findOrFail($id);
        if ($tournee->statut !== 'planifiee') {
            return response()->json(['message' => "Impossible de modifier l'ordre"], 422);
        }
        $sequences->ordonner($tournee);

        return response()->json($this->detail($tournee));
    }

    /** Ajoute à la tournée planifiée les bons prêts des clients de sa route, dans l'ordre de passage. */
    public function ajouterRoute(int $id, SequencesLivraison $sequences): JsonResponse
    {
        $tournee = Tournee::findOrFail($id);
        if ($tournee->statut !== 'planifiee') {
            return response()->json(['message' => "Impossible d'ajouter des bons à cette tournée"], 422);
        }
        if (! $tournee->itineraire_id) {
            return response()->json(['message' => 'Cette tournée ne suit pas de route.'], 422);
        }
        $ajoutes = $sequences->ajouterLaRoute($tournee);

        return response()->json(['message' => "{$ajoutes} bon(s) ajouté(s).", 'ajoutes' => $ajoutes] + $this->detail($tournee)->toArray());
    }

    /** Feuille de route du livreur (PDF) : les arrêts dans l'ordre de passage. */
    public function feuilleDeRoute(int $id, FeuilleDeRoutePdf $feuilles): Response
    {
        $tournee = Tournee::findOrFail($id);

        return $feuilles->pdf($tournee)->stream("feuille-de-route-{$tournee->numero}.pdf");
    }

    /** Document de transport des matières dangereuses de la tournée (PDF), s'il y en a à bord. */
    public function matieresDangereuses(int $id, MatieresDangereusesPdf $documents): Response|JsonResponse
    {
        $tournee = Tournee::with('camion')->findOrFail($id);
        $lignes = $documents->lignes($tournee);
        if ($lignes->isEmpty()) {
            return response()->json(['message' => 'Aucune matière dangereuse à transporter dans cette tournée.'], 422);
        }

        return $documents->pdf($tournee, $lignes)->stream("matieres-dangereuses-{$tournee->numero}.pdf");
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_tournee' => 'required|date',
            // Camion de l'entreprise : celui d'une autre était accepté (et la réponse révélait son existence).
            'camion_id' => ['required', TenantRule::exists('camions')],
            'livreur_id' => 'nullable|integer',
            'zone' => 'nullable|string|max:100',
            'heure_depart' => 'nullable|date_format:H:i',
            // Route suivie : ses bons prêts s'ajoutent d'un coup, dans l'ordre de passage des clients.
            'itineraire_id' => ['nullable', 'integer', TenantRule::exists('itineraires')->whereNull('deleted_at')],
        ]);

        $tenantId = $request->attributes->get('tenant')->id;
        $config = Configuration::pourEntite('tournee', $tenantId);
        if (! $config || ! $config->auto_increment) {
            return response()->json(['success' => false, 'message' => 'Numéro requis'], 422);
        }
        $tournee = DB::transaction(fn () => Tournee::create([
            'tenant_id' => $tenantId,
            'numero' => Configuration::consommerNumero('tournee', $tenantId),
            'date_tournee' => $validated['date_tournee'],
            'camion_id' => $validated['camion_id'],
            'livreur_id' => $validated['livreur_id'] ?? null,
            'zone' => $validated['zone'] ?? null,
            'heure_depart' => $validated['heure_depart'] ?? null,
            'itineraire_id' => $validated['itineraire_id'] ?? null,
            'statut' => 'planifiee',
        ]));

        return response()->json($tournee, 201);
    }

    public function ajouterBon(Request $request, int $id): JsonResponse
    {
        $tournee = Tournee::findOrFail($id);

        if ($tournee->statut !== 'planifiee') {
            return response()->json(['message' => 'Impossible d\'ajouter des bons à cette tournée'], 422);
        }

        $validated = $request->validate([
            'bon_livraison_id' => ['required', TenantRule::exists('bons_livraison')],
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

        return response()->json($this->detail($tournee));
    }

    public function retirerBon(int $id, int $bonId, ChargementsTournee $chargements): JsonResponse
    {
        $tournee = Tournee::findOrFail($id);

        if ($tournee->statut !== 'planifiee') {
            return response()->json(['message' => 'Impossible de modifier cette tournée'], 422);
        }

        // Le bon sort du camion : ses colis ne sont plus chargés.
        if (TourneeBon::where('tournee_id', $id)->where('bon_livraison_id', $bonId)->delete()) {
            $chargements->decharger([$bonId]);
        }

        return response()->json($this->detail($tournee));
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

        return response()->json($this->detail($tournee));
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

        return response()->json($this->detail($tournee));
    }

    public function destroy(int $id, ChargementsTournee $chargements): JsonResponse
    {
        $tournee = Tournee::findOrFail($id);

        if ($tournee->statut !== 'planifiee') {
            return response()->json(['message' => 'Seules les tournées planifiées peuvent être supprimées'], 422);
        }

        $chargements->decharger($tournee->tourneeBons()->pluck('bon_livraison_id')->all());
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

        return response()->json($this->detail($tournee));
    }
}
