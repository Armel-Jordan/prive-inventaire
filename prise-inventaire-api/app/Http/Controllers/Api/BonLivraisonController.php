<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BonLivraison;
use App\Models\BonLivraisonLigne;
use App\Models\Lot;
use App\Models\MouvementVente;
use App\Models\NumeroSerie;
use App\Models\Transporteur;
use App\Services\LotsSeries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BonLivraisonController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $query = BonLivraison::with(['facture.client', 'transporteur:id,nom'])->withCount('colis')->where('tenant_id', $tenantId);

        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }
        if ($request->has('mode_livraison')) {
            $query->where('mode_livraison', $request->mode_livraison);
        }
        if ($request->filled('transporteur_id')) {
            $query->where('transporteur_id', $request->integer('transporteur_id'));
        }
        // Numéro du bon ou de sa facture, nom ou code du client, nom noté pour un client de passage (liens « ?recherche= »).
        if ($request->filled('recherche')) {
            $motif = '%'.addcslashes(trim((string) $request->recherche), '%_\\').'%';
            $query->where(fn ($q) => $q->where('numero', 'like', $motif)
                ->orWhereHas('facture', fn ($f) => $f->where('numero', 'like', $motif)->orWhere('passage_nom', 'like', $motif)
                    ->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $motif)->orWhere('code', 'like', $motif))));
        }
        // Bons pas encore placés dans une tournée (liste « Ajouter » d'une tournée).
        if ($request->boolean('sans_tournee')) {
            $query->whereDoesntHave('tourneeBon');
        }

        $bons = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 20));

        return response()->json($bons);
    }

    public function show(int $id): JsonResponse
    {
        $bon = BonLivraison::with([
            'facture.client', 'lignes', 'tourneeBon.tournee', 'transporteur:id,nom,url_suivi',
            'lignes.produit' => fn ($q) => $q->withTrashed()->select('id', 'numero', 'description', 'mesure'),
        ])->findOrFail($id);

        // Le détail donne l'image de la signature (les listes l'omettent).
        return response()->json($bon->makeVisible('signature_client'));
    }

    /**
     * Expédition (phase 1.11b) : transporteur et numéro de suivi du bon, repris de la facture et modifiables tant que le
     * bon n'est ni livré ni annulé. Un bon retiré par le client n'a pas de transporteur.
     */
    public function expedition(Request $request, int $id): JsonResponse
    {
        $bon = BonLivraison::findOrFail($id);
        if (in_array($bon->statut, ['livre_complet', 'livre_partiel', 'annule'], true)) {
            return response()->json(['message' => __("L'expédition d'un bon livré ou annulé ne se modifie plus.")], 422);
        }
        $validated = $request->validate([
            'transporteur_id' => Transporteur::regle($bon->transporteur_id),
            'numero_suivi' => ['nullable', 'string', 'max:60'],
        ]);
        if ($bon->mode_livraison === 'retrait_client' && ! empty($validated['transporteur_id'])) {
            return response()->json(['message' => __('Un bon retiré par le client ne passe pas par un transporteur.')], 422);
        }
        $bon->update($validated);

        return response()->json($bon->load('transporteur:id,nom,url_suivi'));
    }

    public function demarrerPreparation(Request $request, int $id): JsonResponse
    {
        $bon = BonLivraison::findOrFail($id);

        if ($bon->statut !== 'cree') {
            return response()->json(['message' => __('Ce bon ne peut pas être préparé')], 422);
        }

        $bon->statut = 'en_preparation';
        $bon->date_preparation = now();
        $bon->preparateur_id = $request->user()?->id;
        $bon->save();

        $bon->lignes()->update(['statut_ligne' => 'en_cours']);

        return response()->json($bon->load('lignes'));
    }

    public function updateLignes(Request $request, int $id): JsonResponse
    {
        $bon = BonLivraison::with('lignes')->findOrFail($id);

        if (! in_array($bon->statut, ['en_preparation'])) {
            return response()->json(['message' => __('Ce bon ne peut pas être modifié')], 422);
        }

        $validated = $request->validate([
            'lignes' => 'required|array',
            'lignes.*.id' => 'required|exists:bon_livraison_lignes,id',
            'lignes.*.quantite_preparee' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($bon, $validated, $request) {
            foreach ($validated['lignes'] as $ligneData) {
                $ligne = BonLivraisonLigne::find($ligneData['id']);
                if ($ligne->bon_id !== $bon->id) {
                    continue;
                }

                $ancienneQte = $ligne->quantite_preparee;
                $nouvelleQte = min($ligneData['quantite_preparee'], $ligne->quantite_a_livrer);

                $ligne->quantite_preparee = $nouvelleQte;
                $ligne->statut_ligne = $nouvelleQte >= $ligne->quantite_a_livrer ? 'prepare' : 'en_cours';
                $ligne->save();

                if ($nouvelleQte > $ancienneQte) {
                    // En unités de stock (une caisse de 12 sort 12 unités).
                    $diff = (int) round(($nouvelleQte - $ancienneQte) * $ligne->facteurStock());
                    MouvementVente::creerMouvement(
                        $ligne->produit_id,
                        'sortie_preparation',
                        $diff,
                        'secteur', null,
                        'zone_preparation', null,
                        'bon_livraison', $bon->id,
                        "Préparation BL {$bon->numero}",
                        $request->user()?->id
                    );
                }
            }
        });

        return response()->json($bon->fresh('lignes'));
    }

    public function marquerPret(Request $request, int $id): JsonResponse
    {
        $bon = BonLivraison::with('lignes')->findOrFail($id);

        if ($bon->statut !== 'en_preparation') {
            return response()->json(['message' => __("Ce bon n'est pas en préparation")], 422);
        }

        foreach ($bon->lignes as $ligne) {
            if ($ligne->quantite_preparee <= 0) {
                $ligne->quantite_preparee = $ligne->quantite_a_livrer;
                $ligne->save();
            }
        }

        $bon->statut = 'pret';
        $bon->date_pret = now();
        $bon->save();

        return response()->json($bon);
    }

    public function enregistrerLivraison(Request $request, int $id): JsonResponse
    {
        $bon = BonLivraison::with(['lignes', 'facture'])->findOrFail($id);

        if (! in_array($bon->statut, ['pret', 'en_livraison'])) {
            return response()->json(['message' => __('Ce bon ne peut pas être livré')], 422);
        }

        $validated = $request->validate([
            'lignes' => 'required|array',
            'lignes.*.id' => 'required|exists:bon_livraison_lignes,id',
            'lignes.*.quantite_livree' => 'required|integer|min:0',
            // Produits suivis (phase 2.8b) : les lots qui sortent (sinon les premiers à périmer) et les numéros de série.
            'lignes.*.lots' => 'nullable|array',
            'lignes.*.lots.*.lot_id' => 'required|integer',
            'lignes.*.lots.*.quantite' => 'required|numeric|gt:0',
            'lignes.*.numeros_serie' => 'nullable|array|max:10000',
            'lignes.*.numeros_serie.*' => 'nullable|string|max:100',
            // Signature électronique : image PNG ou JPEG encodée (data URL), nom du signataire.
            'signature_client' => ['nullable', 'string', 'max:500000', 'regex:#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#'],
            'signataire_nom' => ['nullable', 'string', 'max:150'],
            'notes_livraison' => 'nullable|string',
        ], ['signature_client.regex' => __('La signature doit être une image (PNG ou JPEG).')]);

        $result = DB::transaction(function () use ($bon, $validated, $request) {
            foreach ($validated['lignes'] as $index => $ligneData) {
                $ligne = BonLivraisonLigne::with('produit')->find($ligneData['id']);
                if ($ligne->bon_id !== $bon->id) {
                    continue;
                }

                $ligne->quantite_livree = min($ligneData['quantite_livree'], $ligne->quantite_preparee);
                $ligne->statut_ligne = 'livre';
                $ligne->save();

                MouvementVente::creerMouvement(
                    $ligne->produit_id,
                    'livraison_client',
                    (int) round($ligne->quantite_livree * $ligne->facteurStock()),
                    'camion', null,
                    'client', $bon->facture->client_id,
                    'bon_livraison', $bon->id,
                    "Livraison BL {$bon->numero}",
                    $request->user()?->id
                );
                if ($ligne->produit?->suivi) {
                    app(LotsSeries::class)->livrer($ligne, $ligne->produit, round($ligne->quantite_livree * $ligne->facteurStock(), 4), $ligneData['lots'] ?? null,
                        $ligneData['numeros_serie'] ?? null, $bon->facture?->client_id, "lignes.{$index}", $request->user()?->id);
                }
            }

            $signature = $validated['signature_client'] ?? null;
            $bon->signature_client = $signature;
            $bon->signataire_nom = $signature && filled($validated['signataire_nom'] ?? null) ? trim($validated['signataire_nom']) : null;
            $bon->signe_le = $signature ? now() : null;
            $bon->notes_livraison = $validated['notes_livraison'] ?? null;
            $bon->date_livraison = now();

            // Les lignes viennent d'être livrées par d'autres instances : on les recharge avant de conclure.
            // Sans cela, estComplet() lisait les quantités d'avant la livraison : toute livraison était vue
            // comme partielle et une facture du « reste » était émise pour la totalité du bon.
            $bon->load('lignes');
            // Livraison partielle : la facture couvre déjà toute la quantité ; le reste se livre avec un nouveau bon de
            // la facture (ou se règle par une note de crédit s'il ne sera pas livré). Rien n'est refacturé.
            $bon->statut = $bon->estComplet() ? 'livre_complet' : 'livre_partiel';
            // Arrêt de la tournée qui porte ce bon : livré à cette heure, ou en échec si rien n'a été livré.
            $bon->tourneeBon?->update([
                'statut' => $bon->lignes->sum('quantite_livree') > 0 ? 'livre' : 'echec',
                'heure_livraison' => now(),
            ]);
            $bon->save();

            return $bon;
        });

        return response()->json($result->fresh(['lignes', 'facture']));
    }

    /**
     * Exemplaires à sortir : pour chaque ligne d'un produit suivi, ses lots qui ont du stock (le premier à périmer
     * d'abord) ou ses numéros de série en stock, et le facteur de la ligne (unités de stock par unité livrée).
     */
    public function exemplaires(int $id, LotsSeries $lots): JsonResponse
    {
        $bon = BonLivraison::with('lignes.produit')->findOrFail($id);

        return response()->json($bon->lignes->filter(fn (BonLivraisonLigne $l) => $l->produit?->suivi)->map(fn (BonLivraisonLigne $l) => [
            'ligne_id' => $l->id,
            'suivi' => $l->produit->suivi,
            'facteur' => $l->facteurStock(),
            'lots' => $l->produit->suivi === 'lot' ? $lots->lotsDisponibles($l->produit->id)->map(fn (Lot $lot) => [
                'id' => $lot->id, 'numero' => $lot->numero, 'date_peremption' => $lot->date_peremption?->toDateString(), 'solde' => round((float) $lot->solde, 4),
            ])->values() : [],
            'series' => $l->produit->suivi === 'serie' ? NumeroSerie::query()->where('produit_id', $l->produit->id)->where('statut', NumeroSerie::EN_STOCK)
                ->orderBy('numero')->limit(500)->pluck('numero') : [],
        ])->values());
    }

    public function annuler(int $id): JsonResponse
    {
        $bon = BonLivraison::findOrFail($id);

        if (! in_array($bon->statut, ['cree', 'en_preparation'])) {
            return response()->json(['message' => __('Seuls les bons créés ou en préparation peuvent être annulés')], 422);
        }

        $bon->statut = 'annule';
        $bon->save();

        return response()->json($bon);
    }
}
