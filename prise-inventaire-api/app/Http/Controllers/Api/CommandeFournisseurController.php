<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Configuration;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Services\CommandesFournisseur;
use App\Services\ReliquatsClients;
use App\Services\UnitesAchat;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommandeFournisseurController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $query = ComFourEntete::with(['fournisseur', 'createdBy', 'entrepot:id,code,nom'])->where('tenant_id', $tenantId);

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
            'fournisseur_id' => ['required', TenantRule::exists('fournisseurs')],
            'date_commande' => 'required|date',
            'date_livraison_prevue' => 'nullable|date|after_or_equal:date_commande',
            'notes' => 'nullable|string',
            'devise' => 'nullable|string|max:10',
            'taux_change' => 'nullable|numeric|gt:0',
            'entrepot_id' => $this->regleEntrepot(),
            'lignes' => 'required|array|min:1',
            'lignes.*.produit_id' => ['required', TenantRule::exists('produits')],
            'lignes.*.quantite_commandee' => 'required|integer|min:1',
            'lignes.*.prix_unitaire' => 'required|numeric|min:0',
        ]);

        $tenantId = $request->attributes->get('tenant')->id;
        // Numérotation propre aux commandes fournisseur : elles consommaient celle des commandes clients,
        // qui avait alors des trous.
        $config = Configuration::pourEntite('commande_fournisseur', $tenantId);
        if (! $config || ! $config->auto_increment) {
            return response()->json(['success' => false, 'message' => 'Numéro requis'], 422);
        }
        $commande = DB::transaction(function () use ($validated, $request, $tenantId) {
            $numero = Configuration::consommerNumero('commande_fournisseur', $tenantId);

            $commande = ComFourEntete::create([
                'tenant_id' => $tenantId,
                'numero' => $numero,
                'fournisseur_id' => $validated['fournisseur_id'],
                'date_commande' => $validated['date_commande'],
                'date_livraison_prevue' => $validated['date_livraison_prevue'] ?? null,
                'notes' => $validated['notes'] ?? null,
                // Sans devise transmise : celle du fournisseur (et non l'euro d'office).
                'devise' => $validated['devise'] ?? Fournisseur::find($validated['fournisseur_id'])?->devise ?? 'EUR',
                'taux_change' => $validated['taux_change'] ?? 1.0,
                'entrepot_id' => $validated['entrepot_id'] ?? null,
                'statut' => ComFourEntete::STATUT_BROUILLON,
                'created_by' => $request->user()->id,
            ]);

            foreach ($validated['lignes'] as $ligne) {
                $produit = ProduitTenant::find($ligne['produit_id']);
                // Conditionnement du fournisseur (son catalogue), sinon celui du produit.
                ComFourLigne::create(app(UnitesAchat::class)->pour($produit, (int) $validated['fournisseur_id']) + [
                    'com_four_entete_id' => $commande->id,
                    'produit_id' => $ligne['produit_id'],
                    'quantite_commandee' => $ligne['quantite_commandee'],
                    'prix_unitaire' => $ligne['prix_unitaire'],
                    'montant_ligne' => $ligne['quantite_commandee'] * $ligne['prix_unitaire'],
                ]);
            }

            $commande->recalculerMontantTotal();

            return $commande;
        });

        return response()->json($commande->load(['fournisseur', 'lignes.produit', 'entrepot']), 201);
    }

    /** Entrepôt où livrer (« Livrer à ») ; celui de la commande reste accepté s'il a été retiré du référentiel depuis. */
    private function regleEntrepot(?ComFourEntete $commande = null): array
    {
        return ['nullable', 'integer', TenantRule::exists('entrepots')->where(fn ($q) => $q->whereNull('deleted_at')
            ->when($commande?->entrepot_id, fn ($q, $actuel) => $q->orWhere('id', $actuel)))];
    }

    public function show(ComFourEntete $commande): JsonResponse
    {
        $commande->load(['fournisseur', 'lignes.produit', 'lignes.receptions' => fn ($q) => $q->where('annulee', false)->with('reception:id,numero'), 'createdBy', 'auteurEnvoi:id,nom', 'entrepot']);

        return response()->json($commande);
    }

    public function update(Request $request, ComFourEntete $commande): JsonResponse
    {
        if ($commande->statut !== ComFourEntete::STATUT_BROUILLON) {
            return response()->json([
                'message' => 'Seules les commandes en brouillon peuvent être modifiées.',
            ], 422);
        }

        $validated = $request->validate([
            'fournisseur_id' => ['required', TenantRule::exists('fournisseurs')],
            'date_commande' => 'required|date',
            'date_livraison_prevue' => 'nullable|date|after_or_equal:date_commande',
            'notes' => 'nullable|string',
            'entrepot_id' => $this->regleEntrepot($commande),
            'lignes' => 'required|array|min:1',
            'lignes.*.id' => 'nullable|exists:com_four_ligne,id',
            'lignes.*.produit_id' => ['required', TenantRule::exists('produits')],
            'lignes.*.quantite_commandee' => 'required|integer|min:1',
            'lignes.*.prix_unitaire' => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($validated, $commande) {
            $commande->update([
                'fournisseur_id' => $validated['fournisseur_id'],
                'date_commande' => $validated['date_commande'],
                'date_livraison_prevue' => $validated['date_livraison_prevue'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ] + (array_key_exists('entrepot_id', $validated) ? ['entrepot_id' => $validated['entrepot_id']] : []));

            $existingIds = collect($validated['lignes'])->pluck('id')->filter()->toArray();
            // Reliquats clients des lignes retirées ou changées de produit : ils redeviennent inscrits.
            $changees = collect($validated['lignes'])->filter(fn ($l) => isset($l['id']))
                ->filter(fn ($l) => (int) $commande->lignes()->whereKey($l['id'])->value('produit_id') !== (int) $l['produit_id'])->pluck('id');
            app(ReliquatsClients::class)->liberer($commande->lignes()->whereNotIn('id', $existingIds)->pluck('id')->merge($changees));
            $commande->lignes()->whereNotIn('id', $existingIds)->delete();

            foreach ($validated['lignes'] as $ligne) {
                $produit = ProduitTenant::find($ligne['produit_id']);
                // Conditionnement du fournisseur (son catalogue), sinon celui du produit.
                $conditionnement = app(UnitesAchat::class)->pour($produit, (int) $validated['fournisseur_id']);
                if (isset($ligne['id'])) {
                    // Scoper à la commande courante (tenant-scopée) : empêche de modifier la ligne d'un autre tenant par id.
                    $commande->lignes()->where('id', $ligne['id'])->update($conditionnement + [
                        'produit_id' => $ligne['produit_id'],
                        'quantite_commandee' => $ligne['quantite_commandee'],
                        'prix_unitaire' => $ligne['prix_unitaire'],
                        'montant_ligne' => $ligne['quantite_commandee'] * $ligne['prix_unitaire'],
                    ]);
                } else {
                    ComFourLigne::create($conditionnement + [
                        'com_four_entete_id' => $commande->id,
                        'produit_id' => $ligne['produit_id'],
                        'quantite_commandee' => $ligne['quantite_commandee'],
                        'prix_unitaire' => $ligne['prix_unitaire'],
                        'montant_ligne' => $ligne['quantite_commandee'] * $ligne['prix_unitaire'],
                    ]);
                }
            }

            $commande->recalculerMontantTotal();
        });

        return response()->json($commande->fresh()->load(['fournisseur', 'lignes.produit', 'entrepot']));
    }

    public function valider(Request $request, ComFourEntete $commande, CommandesFournisseur $commandes): JsonResponse
    {
        if ($commande->statut !== ComFourEntete::STATUT_BROUILLON) {
            return response()->json([
                'message' => 'Seules les commandes en brouillon peuvent être validées.',
            ], 422);
        }

        $commandes->valider($commande, $request->user()?->id);

        return response()->json([
            'message' => 'Commande validée et envoyée.',
            'commande' => $commande->fresh()->load(['fournisseur', 'lignes.produit']),
        ]);
    }

    public function annuler(ComFourEntete $commande): JsonResponse
    {
        if (in_array($commande->statut, [ComFourEntete::STATUT_COMPLETE, ComFourEntete::STATUT_ANNULEE])) {
            return response()->json([
                'message' => 'Cette commande ne peut pas être annulée.',
            ], 422);
        }

        if ($commande->lignes()->where('quantite_recue', '>', 0)->exists()) {
            return response()->json([
                'message' => 'Impossible d\'annuler une commande avec des réceptions. Utilisez "Clôturer le reste" pour fermer une commande partielle.',
            ], 422);
        }

        $commande->statut = ComFourEntete::STATUT_ANNULEE;
        $commande->save();
        // Ses reliquats clients redeviennent inscrits : la demande du client n'est pas annulée avec la commande.
        app(ReliquatsClients::class)->liberer($commande->lignes()->pluck('id'));

        return response()->json([
            'message' => 'Commande annulée.',
            'commande' => $commande,
        ]);
    }

    public function cloturer(ComFourEntete $commande): JsonResponse
    {
        if ($commande->statut !== ComFourEntete::STATUT_PARTIELLE) {
            return response()->json([
                'message' => 'Seules les commandes partiellement reçues peuvent être clôturées.',
            ], 422);
        }

        foreach ($commande->lignes as $ligne) {
            if ($ligne->quantite_recue < $ligne->quantite_commandee) {
                // Le reste abandonné reste visible : la quantité commandée est ramenée au reçu.
                $ligne->quantite_abandonnee += $ligne->quantite_commandee - $ligne->quantite_recue;
                $ligne->quantite_commandee = $ligne->quantite_recue;
                $ligne->montant_ligne = $ligne->quantite_recue * $ligne->prix_unitaire;
                $ligne->saveQuietly();
            }
        }

        $commande->recalculerMontantTotal();
        $commande->statut = ComFourEntete::STATUT_COMPLETE;
        $commande->save();
        // Reliquats clients : reçus en partie, ils se facturent à la quantité reçue ; sans rien reçu, ils redeviennent inscrits.
        app(ReliquatsClients::class)->commandeCloturee($commande);

        return response()->json([
            'message' => 'Commande clôturée. Le reste non reçu a été annulé.',
            'commande' => $commande->load('lignes.produit', 'fournisseur'),
        ]);
    }

    public function destroy(ComFourEntete $commande): JsonResponse
    {
        if ($commande->statut !== ComFourEntete::STATUT_BROUILLON) {
            return response()->json([
                'message' => 'Seules les commandes en brouillon peuvent être supprimées.',
            ], 422);
        }

        app(ReliquatsClients::class)->liberer($commande->lignes()->pluck('id'));
        $commande->delete();

        return response()->json(['message' => 'Commande supprimée.']);
    }
}
