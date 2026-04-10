<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Configuration;
use App\Models\Devis;
use App\Models\DevisLigne;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DevisController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $query = Devis::with(['client', 'lignes.produit', 'createdBy'])->where('tenant_id', $tenantId);

        if ($request->has('statut') && $request->statut) {
            $query->where('statut', $request->statut);
        }

        if ($request->has('client_id') && $request->client_id) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('numero', 'like', "%{$search}%")
                  ->orWhereHas('client', fn($q2) => $q2->where('raison_sociale', 'like', "%{$search}%"));
            });
        }

        $devis = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

        return response()->json($devis);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id'      => 'required|exists:clients,id',
            'date_devis'     => 'required|date',
            'date_validite'  => 'required|date|after_or_equal:date_devis',
            'notes'          => 'nullable|string',
            'lignes'         => 'required|array|min:1',
            'lignes.*.produit_id'    => 'required|exists:produits,id',
            'lignes.*.quantite'      => 'required|integer|min:1',
            'lignes.*.prix_unitaire' => 'required|numeric|min:0',
        ]);

        $tenantId = $request->attributes->get('tenant')->id;
        $config = Configuration::pourEntite('devis', $tenantId);
        if (!$config || !$config->auto_increment) {
            return response()->json(['success' => false, 'message' => 'Configuration numéro devis manquante'], 422);
        }
        $numero = $config->genererNumero();
        $config->incrementer();

        $devis = DB::transaction(function () use ($validated, $request, $numero, $tenantId) {
            $devis = Devis::create([
                'tenant_id'     => $tenantId,
                'numero'        => $numero,
                'client_id'     => $validated['client_id'],
                'date_devis'    => $validated['date_devis'],
                'date_validite' => $validated['date_validite'],
                'notes'         => $validated['notes'] ?? null,
                'statut'        => Devis::STATUT_BROUILLON,
                'created_by'    => $request->user()->id,
            ]);

            foreach ($validated['lignes'] as $ligne) {
                DevisLigne::create([
                    'devis_id'       => $devis->id,
                    'produit_id'     => $ligne['produit_id'],
                    'quantite'       => $ligne['quantite'],
                    'prix_unitaire'  => $ligne['prix_unitaire'],
                    'montant_ligne'  => $ligne['quantite'] * $ligne['prix_unitaire'],
                ]);
            }

            $devis->recalculerTotal();

            return $devis;
        });

        return response()->json($devis->load(['client', 'lignes.produit']), 201);
    }

    public function show(Devis $devis): JsonResponse
    {
        $devis->load(['client', 'lignes.produit', 'createdBy']);
        return response()->json($devis);
    }

    public function update(Request $request, Devis $devis): JsonResponse
    {
        if ($devis->statut !== Devis::STATUT_BROUILLON) {
            return response()->json(['message' => 'Seuls les devis en brouillon peuvent être modifiés.'], 422);
        }

        $validated = $request->validate([
            'client_id'      => 'required|exists:clients,id',
            'date_devis'     => 'required|date',
            'date_validite'  => 'required|date|after_or_equal:date_devis',
            'notes'          => 'nullable|string',
            'lignes'         => 'required|array|min:1',
            'lignes.*.id'            => 'nullable|exists:devis_lignes,id',
            'lignes.*.produit_id'    => 'required|exists:produits,id',
            'lignes.*.quantite'      => 'required|integer|min:1',
            'lignes.*.prix_unitaire' => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($validated, $devis) {
            $devis->update([
                'client_id'     => $validated['client_id'],
                'date_devis'    => $validated['date_devis'],
                'date_validite' => $validated['date_validite'],
                'notes'         => $validated['notes'] ?? null,
            ]);

            $existingIds = collect($validated['lignes'])->pluck('id')->filter()->toArray();
            $devis->lignes()->whereNotIn('id', $existingIds)->delete();

            foreach ($validated['lignes'] as $ligne) {
                $data = [
                    'produit_id'    => $ligne['produit_id'],
                    'quantite'      => $ligne['quantite'],
                    'prix_unitaire' => $ligne['prix_unitaire'],
                    'montant_ligne' => $ligne['quantite'] * $ligne['prix_unitaire'],
                ];
                if (isset($ligne['id'])) {
                    DevisLigne::where('id', $ligne['id'])->update($data);
                } else {
                    DevisLigne::create(array_merge($data, ['devis_id' => $devis->id]));
                }
            }

            $devis->recalculerTotal();
        });

        return response()->json($devis->fresh()->load(['client', 'lignes.produit']));
    }

    public function envoyer(Devis $devis): JsonResponse
    {
        if ($devis->statut !== Devis::STATUT_BROUILLON) {
            return response()->json(['message' => 'Seuls les devis en brouillon peuvent être envoyés.'], 422);
        }

        $devis->statut = Devis::STATUT_ENVOYE;
        $devis->save();

        return response()->json(['message' => 'Devis envoyé.', 'devis' => $devis->load(['client', 'lignes.produit'])]);
    }

    public function accepter(Devis $devis): JsonResponse
    {
        if ($devis->statut !== Devis::STATUT_ENVOYE) {
            return response()->json(['message' => 'Seuls les devis envoyés peuvent être acceptés.'], 422);
        }

        $devis->statut = Devis::STATUT_ACCEPTE;
        $devis->save();

        return response()->json(['message' => 'Devis accepté.', 'devis' => $devis->load(['client', 'lignes.produit'])]);
    }

    public function refuser(Devis $devis): JsonResponse
    {
        if ($devis->statut !== Devis::STATUT_ENVOYE) {
            return response()->json(['message' => 'Seuls les devis envoyés peuvent être refusés.'], 422);
        }

        $devis->statut = Devis::STATUT_REFUSE;
        $devis->save();

        return response()->json(['message' => 'Devis refusé.', 'devis' => $devis->load(['client', 'lignes.produit'])]);
    }

    public function convertirEnCommande(Request $request, Devis $devis): JsonResponse
    {
        if ($devis->statut !== Devis::STATUT_ACCEPTE) {
            return response()->json(['message' => 'Seuls les devis acceptés peuvent être convertis en commande.'], 422);
        }

        $devis->load('lignes');

        $commande = DB::transaction(function () use ($devis, $request) {
            $numero = \App\Models\ComClientEntete::generateNumero();

            $commande = \App\Models\ComClientEntete::create([
                'numero'        => $numero,
                'client_id'     => $devis->client_id,
                'date_commande' => now()->toDateString(),
                'statut'        => 'brouillon',
                'montant_ht'    => 0,
                'montant_tva'   => 0,
                'montant_ttc'   => 0,
                'notes'         => $devis->notes,
                'created_by'    => $request->user()->id,
            ]);

            foreach ($devis->lignes as $ligne) {
                $l = \App\Models\ComClientLigne::create([
                    'com_entete_id'    => $commande->id,
                    'produit_id'       => $ligne->produit_id,
                    'quantite'         => $ligne->quantite,
                    'prix_unitaire_ht' => $ligne->prix_unitaire,
                    'taux_tva'         => 0,
                    'remise_ligne'     => 0,
                    'montant_ht'       => $ligne->montant_ligne,
                    'montant_ttc'      => $ligne->montant_ligne,
                ]);
            }

            $commande->load('lignes');
            $commande->calculerMontants();
            $commande->save();

            return $commande;
        });

        return response()->json([
            'message'  => 'Devis converti en commande.',
            'commande' => $commande->load(['client', 'lignes']),
        ]);
    }

    public function destroy(Devis $devis): JsonResponse
    {
        if ($devis->statut !== Devis::STATUT_BROUILLON) {
            return response()->json(['message' => 'Seuls les devis en brouillon peuvent être supprimés.'], 422);
        }

        $devis->delete();

        return response()->json(['message' => 'Devis supprimé.']);
    }
}
