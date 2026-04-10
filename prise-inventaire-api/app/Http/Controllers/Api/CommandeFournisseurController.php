<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Configuration;
use App\Models\HistoriquePrixAchat;
use App\Models\ProduitTenant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CommandeFournisseurController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $query = ComFourEntete::with(['fournisseur', 'createdBy'])->where('tenant_id', $tenantId);

        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->has('fournisseur_id')) {
            $query->where('fournisseur_id', $request->fournisseur_id);
        }

        if ($request->has('date_debut')) {
            $query->whereDate('date_commande', '>=', $request->date_debut);
        }

        if ($request->has('date_fin')) {
            $query->whereDate('date_commande', '<=', $request->date_fin);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('numero', 'like', "%{$search}%")
                  ->orWhereHas('fournisseur', function ($q2) use ($search) {
                      $q2->where('raison_sociale', 'like', "%{$search}%");
                  });
            });
        }

        $commandes = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

        return response()->json($commandes);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fournisseur_id' => 'required|exists:fournisseurs,id',
            'date_commande' => 'required|date',
            'date_livraison_prevue' => 'nullable|date|after_or_equal:date_commande',
            'notes' => 'nullable|string',
            'devise' => 'nullable|string|max:10',
            'taux_change' => 'nullable|numeric|min:0',
            'lignes' => 'required|array|min:1',
            'lignes.*.produit_id' => 'required|exists:produits,id',
            'lignes.*.quantite_commandee' => 'required|integer|min:1',
            'lignes.*.prix_unitaire' => 'required|numeric|min:0',
        ]);

        $tenantId = $request->attributes->get('tenant')->id;
        $config = Configuration::pourEntite('commande', $tenantId);
        if (!$config || !$config->auto_increment) {
            return response()->json(['success' => false, 'message' => 'Numéro requis'], 422);
        }
        $numero = $config->genererNumero();
        $config->incrementer();

        $commande = DB::transaction(function () use ($validated, $request, $numero, $tenantId) {
            $commande = ComFourEntete::create([
                'tenant_id' => $tenantId,
                'numero' => $numero,
                'fournisseur_id' => $validated['fournisseur_id'],
                'date_commande' => $validated['date_commande'],
                'date_livraison_prevue' => $validated['date_livraison_prevue'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'devise' => $validated['devise'] ?? 'EUR',
                'taux_change' => $validated['taux_change'] ?? 1.0,
                'statut' => ComFourEntete::STATUT_BROUILLON,
                'created_by' => $request->user()->id,
            ]);

            foreach ($validated['lignes'] as $ligne) {
                $produit = ProduitTenant::find($ligne['produit_id']);
                ComFourLigne::create([
                    'com_four_entete_id' => $commande->id,
                    'produit_id' => $ligne['produit_id'],
                    'quantite_commandee' => $ligne['quantite_commandee'],
                    'unite_achat' => $produit?->unite_achat,
                    'qte_par_unite_achat' => $produit?->qte_par_unite_achat ?? 1,
                    'prix_unitaire' => $ligne['prix_unitaire'],
                    'montant_ligne' => $ligne['quantite_commandee'] * $ligne['prix_unitaire'],
                ]);
            }

            $commande->recalculerMontantTotal();

            return $commande;
        });

        return response()->json($commande->load(['fournisseur', 'lignes.produit']), 201);
    }

    public function show(ComFourEntete $commande): JsonResponse
    {
        $commande->load(['fournisseur', 'lignes.produit', 'lignes.receptions', 'createdBy']);

        return response()->json($commande);
    }

    public function update(Request $request, ComFourEntete $commande): JsonResponse
    {
        if ($commande->statut !== ComFourEntete::STATUT_BROUILLON) {
            return response()->json([
                'message' => 'Seules les commandes en brouillon peuvent être modifiées.'
            ], 422);
        }

        $validated = $request->validate([
            'fournisseur_id' => 'required|exists:fournisseurs,id',
            'date_commande' => 'required|date',
            'date_livraison_prevue' => 'nullable|date|after_or_equal:date_commande',
            'notes' => 'nullable|string',
            'lignes' => 'required|array|min:1',
            'lignes.*.id' => 'nullable|exists:com_four_ligne,id',
            'lignes.*.produit_id' => 'required|exists:produits,id',
            'lignes.*.quantite_commandee' => 'required|integer|min:1',
            'lignes.*.prix_unitaire' => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($validated, $commande) {
            $commande->update([
                'fournisseur_id' => $validated['fournisseur_id'],
                'date_commande' => $validated['date_commande'],
                'date_livraison_prevue' => $validated['date_livraison_prevue'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            $existingIds = collect($validated['lignes'])->pluck('id')->filter()->toArray();
            $commande->lignes()->whereNotIn('id', $existingIds)->delete();

            foreach ($validated['lignes'] as $ligne) {
                $produit = ProduitTenant::find($ligne['produit_id']);
                if (isset($ligne['id'])) {
                    ComFourLigne::where('id', $ligne['id'])->update([
                        'produit_id' => $ligne['produit_id'],
                        'quantite_commandee' => $ligne['quantite_commandee'],
                        'unite_achat' => $produit?->unite_achat,
                        'qte_par_unite_achat' => $produit?->qte_par_unite_achat ?? 1,
                        'prix_unitaire' => $ligne['prix_unitaire'],
                        'montant_ligne' => $ligne['quantite_commandee'] * $ligne['prix_unitaire'],
                    ]);
                } else {
                    ComFourLigne::create([
                        'com_four_entete_id' => $commande->id,
                        'produit_id' => $ligne['produit_id'],
                        'quantite_commandee' => $ligne['quantite_commandee'],
                        'unite_achat' => $produit?->unite_achat,
                        'qte_par_unite_achat' => $produit?->qte_par_unite_achat ?? 1,
                        'prix_unitaire' => $ligne['prix_unitaire'],
                        'montant_ligne' => $ligne['quantite_commandee'] * $ligne['prix_unitaire'],
                    ]);
                }
            }

            $commande->recalculerMontantTotal();
        });

        return response()->json($commande->fresh()->load(['fournisseur', 'lignes.produit']));
    }

    public function valider(ComFourEntete $commande): JsonResponse
    {
        if ($commande->statut !== ComFourEntete::STATUT_BROUILLON) {
            return response()->json([
                'message' => 'Seules les commandes en brouillon peuvent être validées.'
            ], 422);
        }

        DB::transaction(function () use ($commande) {
            $commande->statut = ComFourEntete::STATUT_ENVOYEE;
            $commande->save();

            foreach ($commande->lignes as $ligne) {
                HistoriquePrixAchat::create([
                    'produit_id' => $ligne->produit_id,
                    'fournisseur_id' => $commande->fournisseur_id,
                    'prix_achat' => $ligne->prix_unitaire,
                    'date_effet' => $commande->date_commande,
                    'com_four_entete_id' => $commande->id,
                ]);
            }
        });

        return response()->json([
            'message' => 'Commande validée et envoyée.',
            'commande' => $commande->fresh()->load(['fournisseur', 'lignes.produit'])
        ]);
    }

    public function annuler(ComFourEntete $commande): JsonResponse
    {
        if (in_array($commande->statut, [ComFourEntete::STATUT_COMPLETE, ComFourEntete::STATUT_ANNULEE])) {
            return response()->json([
                'message' => 'Cette commande ne peut pas être annulée.'
            ], 422);
        }

        if ($commande->lignes()->where('quantite_recue', '>', 0)->exists()) {
            return response()->json([
                'message' => 'Impossible d\'annuler une commande avec des réceptions. Utilisez "Clôturer le reste" pour fermer une commande partielle.'
            ], 422);
        }

        $commande->statut = ComFourEntete::STATUT_ANNULEE;
        $commande->save();

        return response()->json([
            'message' => 'Commande annulée.',
            'commande' => $commande
        ]);
    }

    public function cloturer(ComFourEntete $commande): JsonResponse
    {
        if ($commande->statut !== ComFourEntete::STATUT_PARTIELLE) {
            return response()->json([
                'message' => 'Seules les commandes partiellement reçues peuvent être clôturées.'
            ], 422);
        }

        foreach ($commande->lignes as $ligne) {
            if ($ligne->quantite_recue < $ligne->quantite_commandee) {
                $ligne->quantite_commandee = $ligne->quantite_recue;
                $ligne->montant_ligne = $ligne->quantite_recue * $ligne->prix_unitaire;
                $ligne->saveQuietly();
            }
        }

        $commande->recalculerMontantTotal();
        $commande->statut = ComFourEntete::STATUT_COMPLETE;
        $commande->save();

        return response()->json([
            'message' => 'Commande clôturée. Le reste non reçu a été annulé.',
            'commande' => $commande->load('lignes.produit', 'fournisseur')
        ]);
    }

    public function destroy(ComFourEntete $commande): JsonResponse
    {
        if ($commande->statut !== ComFourEntete::STATUT_BROUILLON) {
            return response()->json([
                'message' => 'Seules les commandes en brouillon peuvent être supprimées.'
            ], 422);
        }

        $commande->delete();

        return response()->json(['message' => 'Commande supprimée.']);
    }
}
