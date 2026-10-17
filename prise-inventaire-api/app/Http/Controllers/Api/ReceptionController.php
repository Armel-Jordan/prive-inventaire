<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Reception;
use App\Models\ReceptionArrivagesLigne;
use App\Models\ReceptionFrais;
use App\Services\ReceptionPdf;
use App\Services\ReceptionsFournisseur;
use App\Support\TenantRule;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ReceptionController extends Controller
{
    public function __construct(private readonly ReceptionsFournisseur $receptions) {}

    public function index(Request $request): JsonResponse
    {
        // Scope tenant via le trait sur ComFourEntete (commande) : ne remonte que les réceptions du tenant courant.
        // Les lignes d'une réception annulée ne comptent plus.
        $query = ReceptionArrivagesLigne::query()
            ->whereHas('ligneCommande.commande')
            ->where('annulee', false)
            ->with([
                'reception:id,numero,bon_livraison',
                'ligneCommande.commande.fournisseur',
                'ligneCommande.produit',
                'secteur',
                'receivedBy',
            ]);

        if ($request->has('date_debut')) {
            $query->whereDate('date_reception', '>=', $request->date_debut);
        }

        if ($request->has('date_fin')) {
            $query->whereDate('date_reception', '<=', $request->date_fin);
        }

        if ($request->has('commande_id')) {
            $query->whereHas('ligneCommande', function ($q) use ($request) {
                $q->where('com_four_entete_id', $request->commande_id);
            });
        }

        $receptions = $query->orderBy('date_reception', 'desc')->paginate($request->get('per_page', 15));

        return response()->json($receptions);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'com_four_ligne_id' => 'required|exists:com_four_ligne,id',
            'date_reception' => 'required|date',
            'quantite_recue' => 'required|integer|min:1',
            'cout_unitaire' => 'nullable|numeric|min:0',
            'secteur_id' => ['nullable', TenantRule::exists('secteurs')],
            'lot_numero' => 'nullable|string|max:50',
            'date_peremption' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $ligneCommande = ComFourLigne::findOrFail($validated['com_four_ligne_id']);

        // La relation commande porte le scope tenant (trait sur ComFourEntete) :
        // une ligne d'un autre tenant renvoie une commande null -> 404 (ferme l'IDOR).
        // Vérifié avant les quantités, pour ne rien révéler d'une ligne d'une autre entreprise.
        $commande = $ligneCommande->commande;
        if (! $commande) {
            return response()->json(['message' => 'Ligne de commande introuvable.'], 404);
        }

        $quantiteRestante = $ligneCommande->quantite_commandee - $ligneCommande->quantite_recue;
        if ($validated['quantite_recue'] > $quantiteRestante) {
            return response()->json([
                'message' => "La quantité reçue ({$validated['quantite_recue']}) dépasse la quantité restante ({$quantiteRestante}).",
            ], 422);
        }
        if (! in_array($commande->statut, [ComFourEntete::STATUT_ENVOYEE, ComFourEntete::STATUT_PARTIELLE])) {
            return response()->json([
                'message' => 'Les réceptions ne sont possibles que pour les commandes envoyées ou partiellement reçues.',
            ], 422);
        }

        // Une réception d'une seule ligne est un document comme les autres (numéroté, annulable).
        return $this->executer(function () use ($validated, $request, $commande) {
            $resultat = $this->receptions->enregistrer([
                'date_reception' => $validated['date_reception'],
                'receptions' => [array_intersect_key($validated, array_flip([
                    'com_four_ligne_id', 'quantite_recue', 'cout_unitaire', 'secteur_id', 'lot_numero', 'date_peremption', 'notes',
                ]))],
            ], $commande->tenant_id, $request->user());

            return response()->json([
                'message' => 'Réception enregistrée avec succès.',
                'reception' => $resultat['lignes'][0]->load(['ligneCommande.produit', 'secteur']),
                'document' => $resultat['reception'],
            ], 201);
        });
    }

    /**
     * Réception d'une livraison : lignes d'une ou de plusieurs commandes envoyées du même fournisseur (une ligne peut se
     * répartir sur plusieurs secteurs), avec le bon de livraison, le connaissement et le coût réel ; clôture des lignes
     * que le fournisseur ne livrera pas au complet. Recevoir plus que le reste demande « accepter_surplus ».
     */
    public function receptionMultiple(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'commande_id' => ['nullable', TenantRule::exists('com_four_entete')],
            'date_reception' => 'required|date',
            'bon_livraison' => 'nullable|string|max:60',
            'connaissement' => 'nullable|string|max:60',
            'notes' => 'nullable|string|max:2000',
            'accepter_surplus' => 'boolean',
            'receptions' => 'nullable|array',
            'receptions.*.com_four_ligne_id' => 'required|integer',
            'receptions.*.quantite_recue' => 'required|integer|min:1',
            'receptions.*.cout_unitaire' => 'nullable|numeric|min:0',
            'receptions.*.secteur_id' => ['nullable', TenantRule::exists('secteurs')],
            'receptions.*.lot_numero' => 'nullable|string|max:50',
            'receptions.*.date_peremption' => 'nullable|date',
            'receptions.*.notes' => 'nullable|string',
            'clotures' => 'nullable|array',
            'clotures.*' => 'integer',
            ...$this->reglesFrais(),
        ]);

        if (isset($validated['commande_id'])) {
            $commande = ComFourEntete::findOrFail($validated['commande_id']);
            if (! in_array($commande->statut, [ComFourEntete::STATUT_ENVOYEE, ComFourEntete::STATUT_PARTIELLE])) {
                return response()->json([
                    'message' => 'Les réceptions ne sont possibles que pour les commandes envoyées ou partiellement reçues.',
                ], 422);
            }
        }

        return $this->executer(function () use ($validated, $request) {
            $resultat = $this->receptions->enregistrer($validated, $request->attributes->get('tenant')->id, $request->user());
            $cloturees = count($resultat['cloturees']);
            $message = $resultat['reception']
                ? "Réception {$resultat['reception']->numero} enregistrée : ".count($resultat['lignes']).' ligne(s)'.($cloturees ? ", {$cloturees} ligne(s) clôturée(s)" : '').'.'
                : "{$cloturees} ligne(s) clôturée(s).";

            return response()->json([
                'message' => $message,
                'reception' => $resultat['reception'],
                'receptions' => $resultat['lignes'],
                'cloturees' => $resultat['cloturees'],
            ], $resultat['reception'] ? 201 : 200);
        });
    }

    /** Historique des réceptions (documents), filtrable ; avec leurs commandes et leur valeur. */
    public function documents(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'recherche' => 'nullable|string|max:100',
            'fournisseur_id' => 'nullable|integer',
            'commande_id' => 'nullable|integer',
            'produit_id' => 'nullable|integer',
            'statut' => ['nullable', Rule::in([Reception::ENREGISTREE, Reception::ANNULEE])],
            // Réceptions facturées (1) ou à facturer (0) par une facture fournisseur.
            'facturee' => 'nullable|boolean',
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date',
            'per_page' => 'nullable|integer|between:1,100',
        ]);
        $recherche = trim($filtres['recherche'] ?? '');

        $receptions = Reception::query()
            ->with(['fournisseur:id,code,raison_sociale', 'auteur:id,nom', 'frais', 'factureFournisseur:id,numero', 'lignes.ligneCommande.commande:id,numero,devise,taux_change'])
            ->withCount('lignes')
            ->when($recherche !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('numero', 'like', "%{$recherche}%")
                ->orWhere('bon_livraison', 'like', "%{$recherche}%")
                ->orWhere('connaissement', 'like', "%{$recherche}%")
                ->orWhereHas('fournisseur', fn ($f) => $f->where('raison_sociale', 'like', "%{$recherche}%")->orWhere('code', 'like', "%{$recherche}%"))
                ->orWhereHas('lignes.ligneCommande.commande', fn ($c) => $c->where('numero', 'like', "%{$recherche}%"))))
            ->when($filtres['fournisseur_id'] ?? null, fn ($q, $id) => $q->where('fournisseur_id', $id))
            ->when($filtres['commande_id'] ?? null, fn ($q, $id) => $q->whereHas('lignes.ligneCommande', fn ($l) => $l->where('com_four_entete_id', $id)))
            ->when($filtres['produit_id'] ?? null, fn ($q, $id) => $q->whereHas('lignes.ligneCommande', fn ($l) => $l->where('produit_id', $id)))
            ->when($filtres['statut'] ?? null, fn ($q, $statut) => $q->where('statut', $statut))
            ->when(isset($filtres['facturee']), fn ($q) => $filtres['facturee'] ? $q->whereNotNull('facture_fournisseur_id') : $q->whereNull('facture_fournisseur_id'))
            ->when($filtres['date_debut'] ?? null, fn ($q, $date) => $q->whereDate('date_reception', '>=', $date))
            ->when($filtres['date_fin'] ?? null, fn ($q, $date) => $q->whereDate('date_reception', '<=', $date))
            ->orderByDesc('date_reception')->orderByDesc('id')
            ->paginate($filtres['per_page'] ?? 20);

        return response()->json($receptions->through(fn (Reception $reception) => $this->presenter($reception, false)));
    }

    public function document(int $id): JsonResponse
    {
        return response()->json($this->presenter(Reception::query()->with($this->receptions->relations())->findOrFail($id)));
    }

    /** Correction d'une réception : date, bon de livraison, connaissement, note, coût des lignes (jamais les quantités). */
    public function modifier(Request $request, int $id): JsonResponse
    {
        $reception = Reception::findOrFail($id);
        $donnees = $request->validate([
            'date_reception' => 'sometimes|required|date',
            'bon_livraison' => 'sometimes|nullable|string|max:60',
            'connaissement' => 'sometimes|nullable|string|max:60',
            'notes' => 'sometimes|nullable|string|max:2000',
            'lignes' => 'sometimes|array',
            'lignes.*.id' => 'required|integer',
            'lignes.*.cout_unitaire' => 'nullable|numeric|min:0',
            ...$this->reglesFrais(),
        ]);

        return $this->executer(fn () => response()->json($this->presenter($this->receptions->modifier($reception, $donnees))));
    }

    public function annuler(Request $request, int $id): JsonResponse
    {
        $reception = Reception::findOrFail($id);
        $donnees = $request->validate(['motif' => 'nullable|string|max:255']);

        return $this->executer(fn () => response()->json($this->presenter(
            $this->receptions->annuler($reception, $donnees['motif'] ?? null, $request->user()),
        )));
    }

    public function pdf(int $id, ReceptionPdf $pdf): Response
    {
        $reception = Reception::query()->with($this->receptions->relations())->findOrFail($id);

        return $pdf->pdf($reception)->download("reception-{$reception->numero}.pdf");
    }

    public function commandesEnAttente(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $commandes = ComFourEntete::with(['fournisseur', 'lignes.produit'])
            ->where('tenant_id', $tenantId)
            ->whereIn('statut', [ComFourEntete::STATUT_ENVOYEE, ComFourEntete::STATUT_PARTIELLE])
            ->orderBy('date_livraison_prevue')
            ->get();

        return response()->json($commandes);
    }

    public function lignesEnAttente(ComFourEntete $commande): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        if ($commande->tenant_id !== $tenantId) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $lignes = $commande->lignes()
            ->with('produit')
            ->whereRaw('quantite_recue < quantite_commandee')
            ->get()
            ->map(function ($ligne) {
                $ligne->quantite_restante = $ligne->quantite_commandee - $ligne->quantite_recue;

                return $ligne;
            });

        return response()->json($lignes);
    }

    /** Coûts annexes d'une réception (transport, douane…), répartis au prorata de la valeur, de la quantité, du poids ou du volume. */
    private function reglesFrais(): array
    {
        return [
            'frais' => 'nullable|array|max:20',
            'frais.*.type' => ['required', Rule::in(ReceptionFrais::TYPES)],
            'frais.*.libelle' => 'nullable|string|max:120',
            'frais.*.fournisseur_id' => ['nullable', TenantRule::exists('fournisseurs')],
            'frais.*.reference' => 'nullable|string|max:60',
            'frais.*.montant' => 'required|numeric|gt:0',
            'frais.*.devise' => 'nullable|string|max:10',
            'frais.*.taux_change' => 'nullable|numeric|gt:0',
            'frais.*.repartition' => ['required', Rule::in(ReceptionFrais::REPARTITIONS)],
        ];
    }

    /**
     * Réception telle qu'affichée : ses commandes, et sa valeur dans la devise de ses commandes (quantité × coût réel,
     * sinon prix commandé) et dans celle de l'entreprise ; ses coûts annexes et sa valeur de revient (marchandise et
     * coûts annexes, devise de l'entreprise). Le détail ajoute, par ligne, le coût retenu, le montant et le coût de
     * revient par unité de stock.
     */
    private function presenter(Reception $reception, bool $detail = true): array
    {
        $lignes = $reception->lignes;
        $cout = fn (ReceptionArrivagesLigne $l) => (float) ($l->cout_unitaire ?? $l->ligneCommande?->prix_unitaire ?? 0);
        $taux = fn (ReceptionArrivagesLigne $l) => (float) ($l->ligneCommande?->commande?->taux_change ?: 1);
        $devises = $lignes->map(fn (ReceptionArrivagesLigne $l) => $l->ligneCommande?->commande?->devise)->unique()->values();
        $frais = $reception->relationLoaded('frais') ? $reception->frais : $reception->frais()->get();
        $montantFrais = round($frais->sum(fn (ReceptionFrais $f) => $f->montantReference()), 2);
        $reference = round($lignes->sum(fn ($l) => $l->quantite_recue * $cout($l) * $taux($l)), 2);
        $donnees = $reception->toArray();
        unset($donnees['lignes']);

        return $donnees + [
            'commandes' => $lignes->map(fn (ReceptionArrivagesLigne $l) => $l->ligneCommande?->commande?->numero)->filter()->unique()->values()->all(),
            'devise' => $devises->count() === 1 ? $devises->first() : null,
            'montant' => round($lignes->sum(fn ($l) => $l->quantite_recue * $cout($l)), 2),
            'montant_reference' => $reference,
            'montant_frais' => $montantFrais,
            'valeur_revient' => round($reference + $montantFrais, 2),
        ] + ($detail ? ['lignes' => $lignes->map(fn (ReceptionArrivagesLigne $l) => $l->toArray() + [
            'cout_retenu' => $cout($l),
            'montant' => round($l->quantite_recue * $cout($l), 2),
            // Coût de revient d'une unité de stock (devise de l'entreprise) : coût converti, plus les coûts annexes.
            'cout_revient_unitaire' => round($cout($l) * $taux($l) / max(1, (int) $l->ligneCommande?->qte_par_unite_achat) + (float) $l->frais_unitaire, 4),
        ])->all()] : ['lignes_count' => $reception->lignes_count ?? $lignes->count()]);
    }

    private function executer(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
