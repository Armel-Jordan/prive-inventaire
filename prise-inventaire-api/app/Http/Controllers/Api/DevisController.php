<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComClientEntete;
use App\Models\ComClientLigne;
use App\Models\Configuration;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Services\TaxesDocument;
use App\Support\TauxTaxe;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DevisController extends Controller
{
    public function __construct(private readonly TaxesDocument $taxes) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        // Un devis envoyé dont la validité est passée devient « expiré » (le filtre restait toujours vide).
        Devis::where('tenant_id', $tenantId)->where('statut', Devis::STATUT_ENVOYE)
            ->whereDate('date_validite', '<', now()->toDateString())->update(['statut' => Devis::STATUT_EXPIRE]);
        $query = Devis::with(['client', 'lignes.produit', 'createdBy', 'commande:id,numero'])->where('tenant_id', $tenantId);

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
                    ->orWhereHas('client', fn ($q2) => $q2->where('raison_sociale', 'like', "%{$search}%"));
            });
        }

        $devis = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

        return response()->json($devis);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', TenantRule::exists('clients')],
            'date_devis' => 'required|date',
            'date_validite' => 'required|date|after_or_equal:date_devis',
            'notes' => 'nullable|string',
            'lignes' => 'required|array|min:1',
            'lignes.*.produit_id' => ['required', TenantRule::exists('produits')],
            'lignes.*.quantite' => 'required|integer|min:1',
            'lignes.*.prix_unitaire' => 'required|numeric|min:0',
        ]);

        $tenantId = $request->attributes->get('tenant')->id;
        $config = Configuration::pourEntite('devis', $tenantId);
        if (! $config || ! $config->auto_increment) {
            return response()->json(['success' => false, 'message' => 'Configuration numéro devis manquante'], 422);
        }
        $devis = DB::transaction(function () use ($validated, $request, $tenantId) {
            $numero = Configuration::consommerNumero('devis', $tenantId);

            $devis = Devis::create([
                'tenant_id' => $tenantId,
                'numero' => $numero,
                'client_id' => $validated['client_id'],
                'date_devis' => $validated['date_devis'],
                'date_validite' => $validated['date_validite'],
                'notes' => $validated['notes'] ?? null,
                'statut' => Devis::STATUT_BROUILLON,
                'created_by' => $request->user()->id,
            ]);

            foreach ($validated['lignes'] as $ligne) {
                DevisLigne::create([
                    'devis_id' => $devis->id,
                    'produit_id' => $ligne['produit_id'],
                    'quantite' => $ligne['quantite'],
                    'prix_unitaire' => $ligne['prix_unitaire'],
                    'montant_ligne' => $ligne['quantite'] * $ligne['prix_unitaire'],
                ]);
            }

            $devis->recalculerTotal();

            return $devis;
        });

        return response()->json($devis->load(['client', 'lignes.produit']), 201);
    }

    public function show(Devis $devis): JsonResponse
    {
        $devis->load(['client', 'lignes.produit', 'createdBy', 'commande:id,numero']);

        return response()->json($devis);
    }

    public function update(Request $request, Devis $devis): JsonResponse
    {
        if ($devis->statut !== Devis::STATUT_BROUILLON) {
            return response()->json(['message' => 'Seuls les devis en brouillon peuvent être modifiés.'], 422);
        }

        $validated = $request->validate([
            'client_id' => ['required', TenantRule::exists('clients')],
            'date_devis' => 'required|date',
            'date_validite' => 'required|date|after_or_equal:date_devis',
            'notes' => 'nullable|string',
            'lignes' => 'required|array|min:1',
            'lignes.*.id' => 'nullable|exists:devis_lignes,id',
            'lignes.*.produit_id' => ['required', TenantRule::exists('produits')],
            'lignes.*.quantite' => 'required|integer|min:1',
            'lignes.*.prix_unitaire' => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($validated, $devis) {
            $devis->update([
                'client_id' => $validated['client_id'],
                'date_devis' => $validated['date_devis'],
                'date_validite' => $validated['date_validite'],
                'notes' => $validated['notes'] ?? null,
            ]);

            $existingIds = collect($validated['lignes'])->pluck('id')->filter()->toArray();
            $devis->lignes()->whereNotIn('id', $existingIds)->delete();

            foreach ($validated['lignes'] as $ligne) {
                $data = [
                    'produit_id' => $ligne['produit_id'],
                    'quantite' => $ligne['quantite'],
                    'prix_unitaire' => $ligne['prix_unitaire'],
                    'montant_ligne' => $ligne['quantite'] * $ligne['prix_unitaire'],
                ];
                if (isset($ligne['id'])) {
                    // Scoper au devis courant (tenant-scopé) : empêche de modifier la ligne d'un autre tenant par id.
                    $devis->lignes()->where('id', $ligne['id'])->update($data);
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
        if ($devis->estExpire()) {
            $devis->update(['statut' => Devis::STATUT_EXPIRE]);

            return response()->json(['message' => "Ce devis a expiré le {$devis->date_validite->format('d/m/Y')} : faites-en un nouveau."], 422);
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
        if ($deja = $this->dejaConverti($devis)) {
            return $deja;
        }

        $devis->load('lignes');

        $tenantId = $devis->tenant_id;
        $configCommande = Configuration::pourEntite('commande', $tenantId);
        if (! $configCommande || ! $configCommande->auto_increment) {
            return response()->json(['success' => false, 'message' => 'Configuration numéro commande manquante'], 422);
        }

        $commande = DB::transaction(function () use ($devis, $request, $tenantId) {
            // Verrou sur le devis : deux conversions simultanées ne créent pas deux commandes.
            $verrouille = Devis::whereKey($devis->id)->lockForUpdate()->first();
            if ($verrouille?->commande_id && $verrouille->commande()->exists()) {
                return null;
            }

            // Même séquence que la création normale de commande (cohérence + verrou).
            $numero = Configuration::consommerNumero('commande', $tenantId);

            $commande = ComClientEntete::create([
                'numero' => $numero,
                'client_id' => $devis->client_id,
                'date_commande' => now()->toDateString(),
                'statut' => 'brouillon',
                'montant_ht' => 0,
                'montant_tva' => 0,
                'montant_ttc' => 0,
                'notes' => $devis->notes,
                'created_by' => $request->user()->id,
            ]);

            foreach ($devis->lignes as $ligne) {
                // Un devis ne porte pas de taxe : la commande les calcule comme une commande saisie
                // directement (groupe de taxes par défaut de l'entreprise s'il existe, sinon taux par défaut).
                $ligneCommande = new ComClientLigne([
                    'produit_id' => $ligne->produit_id,
                    'quantite' => $ligne->quantite,
                    'prix_unitaire_ht' => $ligne->prix_unitaire,
                    'taux_tva' => TauxTaxe::parDefaut(),
                    'remise_ligne' => 0,
                ]);
                $ligneCommande->calculerMontants();
                $detail = $this->taxes->appliquer($ligneCommande, $this->taxes->groupePour(['produit_id' => $ligne->produit_id]));
                $commande->lignes()->save($ligneCommande);
                $this->taxes->enregistrer('commande_client', $ligneCommande->id, $detail);
            }

            $commande->load('lignes');
            $commande->calculerMontants();
            $commande->save();
            $devis->update(['commande_id' => $commande->id]);

            return $commande;
        });

        if (! $commande) {
            return $this->dejaConverti($devis->refresh()) ?? response()->json(['message' => 'Conversion impossible pour l\'instant : réessayez.'], 409);
        }

        return response()->json([
            'message' => 'Devis converti en commande.',
            'commande' => $commande->load(['client', 'lignes']),
        ]);
    }

    /** Réponse 422 si le devis a déjà sa commande (toujours existante), sinon null. */
    private function dejaConverti(Devis $devis): ?JsonResponse
    {
        $commande = $devis->commande_id ? $devis->commande()->first(['id', 'numero']) : null;

        return $commande
            ? response()->json(['message' => "Ce devis a déjà été converti en commande {$commande->numero}.", 'commande' => $commande], 422)
            : null;
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
