<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BonLivraison;
use App\Models\BonLivraisonLigne;
use App\Models\Configuration;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\MouvementVente;
use App\Models\ProduitLocalisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BonLivraisonController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = BonLivraison::with(['facture.client']);

        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }
        if ($request->has('mode_livraison')) {
            $query->where('mode_livraison', $request->mode_livraison);
        }

        $bons = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 20));
        return response()->json($bons);
    }

    public function show(int $id): JsonResponse
    {
        $bon = BonLivraison::with(['facture.client', 'lignes', 'tourneeBon.tournee'])->findOrFail($id);
        return response()->json($bon);
    }

    public function demarrerPreparation(Request $request, int $id): JsonResponse
    {
        $bon = BonLivraison::findOrFail($id);

        if ($bon->statut !== 'cree') {
            return response()->json(['message' => 'Ce bon ne peut pas être préparé'], 422);
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

        if (!in_array($bon->statut, ['en_preparation'])) {
            return response()->json(['message' => 'Ce bon ne peut pas être modifié'], 422);
        }

        $validated = $request->validate([
            'lignes' => 'required|array',
            'lignes.*.id' => 'required|exists:bon_livraison_lignes,id',
            'lignes.*.quantite_preparee' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($bon, $validated, $request) {
            foreach ($validated['lignes'] as $ligneData) {
                $ligne = BonLivraisonLigne::find($ligneData['id']);
                if ($ligne->bon_id !== $bon->id) continue;

                $ancienneQte = $ligne->quantite_preparee;
                $nouvelleQte = min($ligneData['quantite_preparee'], $ligne->quantite_a_livrer);

                $ligne->quantite_preparee = $nouvelleQte;
                $ligne->statut_ligne = $nouvelleQte >= $ligne->quantite_a_livrer ? 'prepare' : 'en_cours';
                $ligne->save();

                if ($nouvelleQte > $ancienneQte) {
                    $diff = $nouvelleQte - $ancienneQte;
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
            return response()->json(['message' => 'Ce bon n\'est pas en préparation'], 422);
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

        if (!in_array($bon->statut, ['pret', 'en_livraison'])) {
            return response()->json(['message' => 'Ce bon ne peut pas être livré'], 422);
        }

        $validated = $request->validate([
            'lignes' => 'required|array',
            'lignes.*.id' => 'required|exists:bon_livraison_lignes,id',
            'lignes.*.quantite_livree' => 'required|integer|min:0',
            'signature_client' => 'nullable|string',
            'notes_livraison' => 'nullable|string',
        ]);

        $tenantId = $request->attributes->get('tenant')->id;

        $result = DB::transaction(function () use ($bon, $validated, $request, $tenantId) {
            foreach ($validated['lignes'] as $ligneData) {
                $ligne = BonLivraisonLigne::find($ligneData['id']);
                if ($ligne->bon_id !== $bon->id) continue;

                $ligne->quantite_livree = min($ligneData['quantite_livree'], $ligne->quantite_preparee);
                $ligne->statut_ligne = 'livre';
                $ligne->save();

                MouvementVente::creerMouvement(
                    $ligne->produit_id,
                    'livraison_client',
                    $ligne->quantite_livree,
                    'camion', null,
                    'client', $bon->facture->client_id,
                    'bon_livraison', $bon->id,
                    "Livraison BL {$bon->numero}",
                    $request->user()?->id
                );
            }

            $bon->signature_client = $validated['signature_client'] ?? null;
            $bon->notes_livraison = $validated['notes_livraison'] ?? null;
            $bon->date_livraison = now();

            if ($bon->estComplet()) {
                $bon->statut = 'livre_complet';
            } else {
                $bon->statut = 'livre_partiel';
                $this->genererFactureReste($bon, $tenantId);
            }
            $bon->save();

            return $bon;
        });

        return response()->json($result->fresh(['lignes', 'facture']));
    }

    private function genererFactureReste(BonLivraison $bon, int $tenantId): void
    {
        $factureOrigine = $bon->facture;

        $config = Configuration::pourEntite('facture', $tenantId);
        if ($config && $config->auto_increment) {
            $numeroFacture = $config->genererNumero();
            $config->incrementer();
        } else {
            $numeroFacture = Facture::generateNumero();
        }

        $nouvelleFacture = Facture::create([
            'numero' => $numeroFacture,
            'commande_id' => $factureOrigine->commande_id,
            'client_id' => $factureOrigine->client_id,
            'facture_mere_id' => $factureOrigine->id,
            'date_facture' => now(),
            'statut' => 'brouillon',
        ]);

        $montantHt = 0;
        $montantTva = 0;
        $montantTtc = 0;

        foreach ($bon->lignes as $ligne) {
            $reste = $ligne->quantite_a_livrer - $ligne->quantite_livree;
            if ($reste <= 0) continue;

            $ligneFactureOrigine = $factureOrigine->lignes->where('produit_id', $ligne->produit_id)->first();
            if (!$ligneFactureOrigine) continue;

            $ratio = $reste / $ligne->quantite_a_livrer;
            $montantLigneHt = $ligneFactureOrigine->montant_ht * $ratio;
            $montantLigneTva = $ligneFactureOrigine->montant_tva * $ratio;
            $montantLigneTtc = $ligneFactureOrigine->montant_ttc * $ratio;

            FactureLigne::create([
                'facture_id' => $nouvelleFacture->id,
                'produit_id' => $ligne->produit_id,
                'quantite' => $reste,
                'prix_unitaire_ht' => $ligneFactureOrigine->prix_unitaire_ht,
                'taux_tva' => $ligneFactureOrigine->taux_tva,
                'remise_ligne' => $ligneFactureOrigine->remise_ligne,
                'montant_ht' => $montantLigneHt,
                'montant_tva' => $montantLigneTva,
                'montant_ttc' => $montantLigneTtc,
            ]);

            $montantHt += $montantLigneHt;
            $montantTva += $montantLigneTva;
            $montantTtc += $montantLigneTtc;
        }

        $nouvelleFacture->update([
            'montant_ht' => $montantHt,
            'montant_tva' => $montantTva,
            'montant_ttc' => $montantTtc,
            'reste_a_payer' => $montantTtc,
        ]);
    }
}
