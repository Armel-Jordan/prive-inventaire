<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\RetourFournisseur;
use App\Services\RetoursFournisseurs;
use App\Support\TenantContext;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fiche fournisseur (plan de parité fripv2, phase 3.1) : en-tête, synthèse des achats, historique,
 * produits fournis (catalogue). Lecture seule sur les commandes et réceptions existantes.
 */
class FournisseurFicheController extends Controller
{
    /** Commandes qui comptent comme achats : envoyées, reçues en partie ou en totalité (ni brouillon ni annulée). */
    private const COMMANDES_VALIDES = [ComFourEntete::STATUT_ENVOYEE, ComFourEntete::STATUT_PARTIELLE, ComFourEntete::STATUT_COMPLETE];

    /**
     * Une commande est passée dans la devise du fournisseur ; son taux convertit vers la devise de
     * l'entreprise. Les montants de la synthèse sont convertis ainsi (taux absent ou nul : 1).
     */
    private const TAUX = 'COALESCE(NULLIF(com_four_entete.taux_change, 0), 1)';

    public function show(int $id): JsonResponse
    {
        $fournisseur = Fournisseur::withCount(['contacts', 'adresses', 'comptesBancaires', 'remarques', 'produits'])->findOrFail($id);

        return response()->json($fournisseur);
    }

    public function synthese(int $id): JsonResponse
    {
        $fournisseur = Fournisseur::findOrFail($id);
        $commandes = fn () => ComFourEntete::where('fournisseur_id', $fournisseur->id)->whereIn('statut', self::COMMANDES_VALIDES);
        $annee = (int) now()->year;

        $converti = DB::raw('com_four_entete.montant_total * '.self::TAUX);
        $nombre = $commandes()->count();
        $total = (float) $commandes()->sum($converti);
        $derniere = $commandes()->orderByDesc('date_commande')->orderByDesc('id')->first(['id', 'numero', 'date_commande', 'montant_total', 'devise', 'statut']);
        $premiere = $commandes()->min('date_commande');

        // Achats des 12 derniers mois (mois courant compris), regroupés ici pour rester indépendant du SGBD.
        $debut = now()->startOfMonth()->subMonths(11);
        $parMois = $commandes()->whereDate('date_commande', '>=', $debut->toDateString())->get(['date_commande', 'montant_total', 'taux_change'])
            ->groupBy(fn ($c) => Carbon::parse($c->date_commande)->format('Y-m'))
            ->map(fn ($groupe) => round((float) $groupe->sum(fn ($c) => (float) $c->montant_total * ((float) $c->taux_change > 0 ? (float) $c->taux_change : 1)), 2));
        $mensuel = collect(range(0, 11))->map(function (int $decalage) use ($debut, $parMois) {
            $mois = $debut->copy()->addMonths($decalage)->format('Y-m');

            return ['mois' => $mois, 'montant' => $parMois->get($mois, 0.0)];
        });

        // Reste à recevoir sur les commandes envoyées ou reçues en partie.
        $ouvertes = $this->lignesAchetees($fournisseur)
            ->whereIn('com_four_entete.statut', [ComFourEntete::STATUT_ENVOYEE, ComFourEntete::STATUT_PARTIELLE])
            ->whereColumn('com_four_ligne.quantite_recue', '<', 'com_four_ligne.quantite_commandee')
            ->selectRaw('COUNT(DISTINCT com_four_entete.id) AS nb, COALESCE(SUM(com_four_ligne.quantite_commandee - com_four_ligne.quantite_recue), 0) AS quantite, COALESCE(SUM((com_four_ligne.quantite_commandee - com_four_ligne.quantite_recue) * com_four_ligne.prix_unitaire * '.self::TAUX.'), 0) AS montant')
            ->first();

        // Taux de réception : quantités reçues sur quantités commandées, pour les commandes déjà reçues en tout ou partie.
        $receptions = $this->lignesAchetees($fournisseur)
            ->whereIn('com_four_entete.statut', [ComFourEntete::STATUT_PARTIELLE, ComFourEntete::STATUT_COMPLETE])
            ->selectRaw('COALESCE(SUM(com_four_ligne.quantite_commandee), 0) AS commandee, COALESCE(SUM(com_four_ligne.quantite_recue), 0) AS recue')
            ->first();

        return response()->json([
            'achats' => [
                'total' => round($total, 2),
                'annee_courante' => round((float) $commandes()->whereYear('date_commande', $annee)->sum($converti), 2),
                'annee_precedente' => round((float) $commandes()->whereYear('date_commande', $annee - 1)->sum($converti), 2),
            ],
            'commandes' => [
                'nombre' => $nombre,
                'montant_moyen' => $nombre ? round($total / $nombre, 2) : 0.0,
                'brouillons' => ComFourEntete::where('fournisseur_id', $fournisseur->id)->where('statut', ComFourEntete::STATUT_BROUILLON)->count(),
                'premiere' => $premiere ? Carbon::parse($premiere)->toDateString() : null,
                // Montant de la dernière commande dans sa propre devise.
                'derniere' => $derniere ? [
                    'id' => $derniere->id,
                    'numero' => $derniere->numero,
                    'date' => Carbon::parse($derniere->date_commande)->toDateString(),
                    'montant' => (float) $derniere->montant_total,
                    'devise' => $derniere->devise,
                    'statut' => $derniere->statut,
                ] : null,
            ],
            'a_recevoir' => [
                'commandes' => (int) $ouvertes->nb,
                'quantite' => (int) $ouvertes->quantite,
                'montant' => round((float) $ouvertes->montant, 2),
            ],
            'livraisons' => [
                ...$this->delais($fournisseur),
                'taux_reception' => (float) $receptions->commandee > 0
                    ? round(min(100, 100 * (float) $receptions->recue / (float) $receptions->commandee), 1)
                    : null,
            ],
            'achats_mensuels' => $mensuel,
            'produits_les_plus_achetes' => $this->produitsLesPlusAchetes($fournisseur),
            // Retours au fournisseur : crédits attendus et obtenus (avoirs), retours en cours.
            'retours' => app(RetoursFournisseurs::class)->resume(RetourFournisseur::where('fournisseur_id', $fournisseur->id)),
        ]);
    }

    /** Lignes des commandes du fournisseur, de la plus récente à la plus ancienne, avec filtres et totaux. */
    public function historique(Request $request, int $id): JsonResponse
    {
        $fournisseur = Fournisseur::findOrFail($id);
        $filtres = $request->validate([
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
            'recherche' => ['nullable', 'string', 'max:100'],
            'produit_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $lignes = $this->lignesAchetees($fournisseur)
            ->when($filtres['du'] ?? null, fn ($q, $du) => $q->whereDate('com_four_entete.date_commande', '>=', $du))
            ->when($filtres['au'] ?? null, fn ($q, $au) => $q->whereDate('com_four_entete.date_commande', '<=', $au))
            ->when($filtres['produit_id'] ?? null, fn ($q, $produit) => $q->where('com_four_ligne.produit_id', $produit))
            ->when($filtres['recherche'] ?? null, fn ($q, $texte) => $q->where(fn ($q) => $q
                ->where('produits.numero', 'like', "%{$texte}%")
                ->orWhere('produits.description', 'like', "%{$texte}%")));

        // Totaux calculés avant le tri (requête d'agrégat sans ORDER BY, valable en MySQL strict) ; le
        // montant total est converti dans la devise de l'entreprise, les lignes restent dans la leur.
        $totaux = (clone $lignes)->selectRaw('COALESCE(SUM(com_four_ligne.quantite_commandee), 0) AS commandee, COALESCE(SUM(com_four_ligne.quantite_recue), 0) AS recue, COALESCE(SUM(com_four_ligne.montant_ligne * '.self::TAUX.'), 0) AS montant')->first();

        $page = $lignes->select([
            'com_four_ligne.id', 'com_four_ligne.produit_id', 'com_four_ligne.quantite_commandee', 'com_four_ligne.quantite_recue',
            'com_four_ligne.prix_unitaire', 'com_four_ligne.montant_ligne',
            'com_four_entete.id as commande_id', 'com_four_entete.numero as commande_numero', 'com_four_entete.date_commande', 'com_four_entete.statut', 'com_four_entete.devise',
            'produits.numero as produit_numero', 'produits.description as produit_description',
        ])
            ->orderByDesc('com_four_entete.date_commande')->orderByDesc('com_four_ligne.id')
            ->paginate($filtres['per_page'] ?? 25)
            ->through(fn ($ligne) => [
                'id' => $ligne->id,
                'date' => Carbon::parse($ligne->date_commande)->toDateString(),
                'commande_id' => (int) $ligne->commande_id,
                'commande_numero' => $ligne->commande_numero,
                'statut' => $ligne->statut,
                'produit_id' => (int) $ligne->produit_id,
                'produit_numero' => $ligne->produit_numero,
                'produit_description' => $ligne->produit_description,
                'quantite_commandee' => (int) $ligne->quantite_commandee,
                'quantite_recue' => (int) $ligne->quantite_recue,
                'prix_unitaire' => (float) $ligne->prix_unitaire,
                'montant' => (float) $ligne->montant_ligne,
                'devise' => $ligne->devise,
            ]);

        return response()->json([
            ...$page->toArray(),
            'totaux' => [
                'quantite_commandee' => (int) $totaux->commandee,
                'quantite_recue' => (int) $totaux->recue,
                'montant' => round((float) $totaux->montant, 2),
            ],
        ]);
    }

    /** Produits fournis : référence et prix convenus, délai, fournisseur principal, et dernier prix payé. */
    public function produits(int $id): JsonResponse
    {
        $fournisseur = Fournisseur::findOrFail($id);
        $produits = $fournisseur->produits()->orderBy('produits.numero')
            ->get(['produits.id', 'produits.numero', 'produits.description', 'produits.mesure', 'produits.unite_achat', 'produits.qte_par_unite_achat']);

        $derniers = $this->lignesAchetees($fournisseur)
            ->whereIn('com_four_ligne.produit_id', $produits->pluck('id'))
            ->orderByDesc('com_four_entete.date_commande')->orderByDesc('com_four_ligne.id')
            ->get(['com_four_ligne.produit_id', 'com_four_ligne.prix_unitaire', 'com_four_entete.date_commande', 'com_four_entete.devise'])
            ->unique('produit_id')->keyBy('produit_id');

        return response()->json($produits->map(fn (ProduitTenant $produit) => [
            'produit_id' => $produit->id,
            'numero' => $produit->numero,
            'description' => $produit->description,
            'reference_fournisseur' => $produit->pivot->reference_fournisseur,
            'prix_achat' => $produit->pivot->prix_achat !== null ? (float) $produit->pivot->prix_achat : null,
            'delai_livraison' => $produit->pivot->delai_livraison !== null ? (int) $produit->pivot->delai_livraison : null,
            'fournisseur_principal' => (bool) $produit->pivot->fournisseur_principal,
            // Conditionnement chez ce fournisseur (vide : celui du produit), et celui du produit.
            'unite_achat' => $produit->pivot->unite_achat,
            'qte_par_unite_achat' => $produit->pivot->qte_par_unite_achat !== null ? (int) $produit->pivot->qte_par_unite_achat : null,
            'mesure' => $produit->mesure,
            'unite_achat_produit' => $produit->unite_achat,
            'qte_par_unite_achat_produit' => (int) ($produit->qte_par_unite_achat ?? 1),
            // Dernier prix payé, dans la devise de la commande.
            'dernier_prix' => isset($derniers[$produit->id]) ? (float) $derniers[$produit->id]->prix_unitaire : null,
            'dernier_prix_devise' => $derniers[$produit->id]->devise ?? null,
            'derniere_commande' => isset($derniers[$produit->id]) ? Carbon::parse($derniers[$produit->id]->date_commande)->toDateString() : null,
        ])->values());
    }

    public function ajouterProduit(Request $request, int $id): JsonResponse
    {
        $fournisseur = Fournisseur::findOrFail($id);
        $donnees = $request->validate([
            'produit_id' => ['required', 'integer', TenantRule::exists('produits')],
            ...$this->reglesProduit(),
        ]);
        if ($fournisseur->produits()->whereKey($donnees['produit_id'])->exists()) {
            throw ValidationException::withMessages(['produit_id' => __('Ce produit est déjà au catalogue de ce fournisseur.')]);
        }

        DB::transaction(function () use ($fournisseur, $donnees) {
            $fournisseur->produits()->attach($donnees['produit_id'], $this->pivot($donnees));
            $this->garderUnSeulFournisseurPrincipal($fournisseur, (int) $donnees['produit_id'], $donnees);
        });

        return response()->json(['message' => __('Produit ajouté au catalogue.')], 201);
    }

    public function modifierProduit(Request $request, int $id, int $produit): JsonResponse
    {
        $fournisseur = Fournisseur::findOrFail($id);
        abort_unless($fournisseur->produits()->whereKey($produit)->exists(), 404, __('Produit absent du catalogue de ce fournisseur.'));
        $donnees = $request->validate($this->reglesProduit());

        DB::transaction(function () use ($fournisseur, $produit, $donnees) {
            $fournisseur->produits()->updateExistingPivot($produit, $this->pivot($donnees));
            $this->garderUnSeulFournisseurPrincipal($fournisseur, $produit, $donnees);
        });

        return response()->json(['message' => __('Catalogue mis à jour.')]);
    }

    public function retirerProduit(int $id, int $produit): JsonResponse
    {
        $fournisseur = Fournisseur::findOrFail($id);
        abort_unless($fournisseur->produits()->detach($produit) > 0, 404, __('Produit absent du catalogue de ce fournisseur.'));

        return response()->json(['message' => __('Produit retiré du catalogue.')]);
    }

    private function reglesProduit(): array
    {
        return [
            'reference_fournisseur' => ['nullable', 'string', 'max:50'],
            'prix_achat' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'delai_livraison' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'fournisseur_principal' => ['sometimes', 'boolean'],
            // Conditionnement de ce fournisseur : son unité d'achat et les unités de stock qu'elle contient.
            'unite_achat' => ['nullable', 'string', 'max:30'],
            'qte_par_unite_achat' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ];
    }

    private function pivot(array $donnees): array
    {
        $pivot = array_intersect_key($donnees, array_flip(['reference_fournisseur', 'prix_achat', 'delai_livraison', 'fournisseur_principal', 'unite_achat', 'qte_par_unite_achat']));
        if (array_key_exists('unite_achat', $pivot)) {
            $pivot['unite_achat'] = mb_strtoupper(trim((string) $pivot['unite_achat'])) ?: null;
        }

        return $pivot;
    }

    /** Un produit n'a qu'un fournisseur principal : le marquer chez celui-ci le retire chez les autres. */
    private function garderUnSeulFournisseurPrincipal(Fournisseur $fournisseur, int $produit, array $donnees): void
    {
        if (! empty($donnees['fournisseur_principal'])) {
            DB::table('produit_fournisseur')->where('produit_id', $produit)->where('fournisseur_id', '!=', $fournisseur->id)
                ->update(['fournisseur_principal' => false]);
        }
    }

    /**
     * Lignes des commandes valides du fournisseur. ComFourLigne n'est pas cloisonnée : l'entreprise est
     * filtrée explicitement sur la commande, et le produit est joint dans la même entreprise (même supprimé).
     */
    private function lignesAchetees(Fournisseur $fournisseur)
    {
        return ComFourLigne::query()
            ->join('com_four_entete', 'com_four_entete.id', '=', 'com_four_ligne.com_four_entete_id')
            ->leftJoin('produits', fn ($j) => $j->on('produits.id', '=', 'com_four_ligne.produit_id')->on('produits.tenant_id', '=', 'com_four_entete.tenant_id'))
            ->where('com_four_entete.tenant_id', app(TenantContext::class)->getTenantId())
            ->where('com_four_entete.fournisseur_id', $fournisseur->id)
            ->whereIn('com_four_entete.statut', self::COMMANDES_VALIDES);
    }

    /**
     * Délai de livraison des commandes entièrement reçues : jours entre la commande et sa dernière réception,
     * et part des commandes livrées au plus tard à la date prévue (quand elle est renseignée).
     */
    private function delais(Fournisseur $fournisseur): array
    {
        $livrees = DB::table('reception_arrivages_ligne')
            ->join('com_four_ligne', 'com_four_ligne.id', '=', 'reception_arrivages_ligne.com_four_ligne_id')
            ->join('com_four_entete', 'com_four_entete.id', '=', 'com_four_ligne.com_four_entete_id')
            ->where('com_four_entete.tenant_id', app(TenantContext::class)->getTenantId())
            ->where('com_four_entete.fournisseur_id', $fournisseur->id)
            ->where('com_four_entete.statut', ComFourEntete::STATUT_COMPLETE)
            ->where('reception_arrivages_ligne.annulee', false)
            ->groupBy('com_four_entete.id', 'com_four_entete.date_commande', 'com_four_entete.date_livraison_prevue')
            ->selectRaw('com_four_entete.id, com_four_entete.date_commande, com_four_entete.date_livraison_prevue, MAX(reception_arrivages_ligne.date_reception) AS derniere_reception')
            ->get();

        $jours = $livrees->map(fn ($c) => max(0, Carbon::parse($c->date_commande)->startOfDay()->diffInDays(Carbon::parse($c->derniere_reception)->startOfDay(), false)));
        $avecDatePrevue = $livrees->filter(fn ($c) => $c->date_livraison_prevue !== null);
        $aTemps = $avecDatePrevue->filter(fn ($c) => Carbon::parse($c->derniere_reception)->lte(Carbon::parse($c->date_livraison_prevue)->endOfDay()));

        return [
            'commandes_livrees' => $livrees->count(),
            'delai_moyen_jours' => $jours->isNotEmpty() ? round((float) $jours->avg(), 1) : null,
            'a_temps' => $avecDatePrevue->isNotEmpty() ? round(100 * $aTemps->count() / $avecDatePrevue->count(), 1) : null,
        ];
    }

    /** Les 5 produits qui pèsent le plus dans les achats au fournisseur. */
    private function produitsLesPlusAchetes(Fournisseur $fournisseur): array
    {
        $lignes = $this->lignesAchetees($fournisseur)
            ->groupBy('com_four_ligne.produit_id')
            ->selectRaw('com_four_ligne.produit_id, SUM(com_four_ligne.quantite_commandee) AS total_quantite, SUM(com_four_ligne.montant_ligne * '.self::TAUX.') AS total_montant, COUNT(DISTINCT com_four_entete.id) AS nb_commandes, MAX(com_four_entete.date_commande) AS dernier_achat')
            ->orderByDesc('total_montant')
            ->limit(5)
            ->get();
        $produits = ProduitTenant::withTrashed()->whereIn('id', $lignes->pluck('produit_id'))->get(['id', 'numero', 'description'])->keyBy('id');

        return $lignes->map(fn ($ligne) => [
            'produit_id' => (int) $ligne->produit_id,
            'numero' => $produits->get($ligne->produit_id)?->numero,
            'description' => $produits->get($ligne->produit_id)?->description,
            'quantite' => (int) $ligne->total_quantite,
            'montant' => round((float) $ligne->total_montant, 2),
            'commandes' => (int) $ligne->nb_commandes,
            'dernier_achat' => $ligne->dernier_achat ? Carbon::parse($ligne->dernier_achat)->toDateString() : null,
        ])->all();
    }
}
