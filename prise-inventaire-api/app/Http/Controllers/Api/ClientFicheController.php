<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientFavori;
use App\Models\ComClientEntete;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\ProduitTenant;
use App\Support\TenantContext;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fiche client (plan de parité fripv2, phase 1.1) : en-tête, synthèse des ventes, historique d'achats,
 * catégorie et favori. Lecture seule sur les documents existants : rien n'y est modifié.
 */
class ClientFicheController extends Controller
{
    /** Factures qui comptent comme ventes : émises, partiellement payées ou payées (ni brouillon ni annulée). */
    private const FACTURES_VALIDES = ['emise', 'partiellement_payee', 'payee'];

    public function show(Request $request, int $id): JsonResponse
    {
        $client = Client::with(['categorie:id,nom', 'conditionsPaiement', 'representant:id,nom'])
            ->withCount(['contacts', 'adresses', 'remarques', 'referencesProduits as references_count'])
            ->withExists(['favoris as favori' => fn ($q) => $q->where('user_id', $request->user()->id)])
            ->findOrFail($id);

        return response()->json($client);
    }

    public function synthese(int $id): JsonResponse
    {
        $client = Client::findOrFail($id);
        $factures = fn () => Facture::where('client_id', $client->id)->whereIn('statut', self::FACTURES_VALIDES);
        $ouvertes = fn () => Facture::where('client_id', $client->id)->whereIn('statut', ['emise', 'partiellement_payee'])->where('reste_a_payer', '>', 0);
        $annee = (int) now()->year;

        $nombreFactures = $factures()->count();
        // Montants dans la devise de l'entreprise, chaque facture à son taux.
        $total = (float) $factures()->sum(DB::raw('montant_ht * taux_change'));
        $derniere = $factures()->orderByDesc('date_facture')->orderByDesc('id')->first(['id', 'numero', 'date_facture', 'montant_ttc']);
        $premiere = $factures()->min('date_facture');

        // Ventes des 12 derniers mois (mois courant compris), regroupées ici pour rester indépendant du SGBD.
        $debut = now()->startOfMonth()->subMonths(11);
        $parMois = $factures()->whereDate('date_facture', '>=', $debut->toDateString())->get(['date_facture', 'montant_ht', 'taux_change'])
            ->groupBy(fn ($f) => Carbon::parse($f->date_facture)->format('Y-m'))
            ->map(fn ($groupe) => round((float) $groupe->sum(fn ($f) => (float) $f->montant_ht * (float) ($f->taux_change ?: 1)), 2));
        $mensuel = collect(range(0, 11))->map(function (int $decalage) use ($debut, $parMois) {
            $mois = $debut->copy()->addMonths($decalage)->format('Y-m');

            return ['mois' => $mois, 'montant_ht' => $parMois->get($mois, 0.0)];
        });

        return response()->json([
            'chiffre_affaires' => [
                'total' => round($total, 2),
                'annee_courante' => round((float) $factures()->whereYear('date_facture', $annee)->sum(DB::raw('montant_ht * taux_change')), 2),
                'annee_precedente' => round((float) $factures()->whereYear('date_facture', $annee - 1)->sum(DB::raw('montant_ht * taux_change')), 2),
            ],
            'factures' => [
                'nombre' => $nombreFactures,
                'panier_moyen' => $nombreFactures ? round($total / $nombreFactures, 2) : 0.0,
                'premiere' => $premiere ? Carbon::parse($premiere)->toDateString() : null,
                'derniere' => $derniere ? [
                    'id' => $derniere->id,
                    'numero' => $derniere->numero,
                    'date' => $derniere->date_facture->toDateString(),
                    'montant_ttc' => (float) $derniere->montant_ttc,
                ] : null,
            ],
            'encours' => [
                'montant' => round((float) $ouvertes()->sum(DB::raw('reste_a_payer * taux_change')), 2),
                'maximum' => $client->encours_max !== null ? (float) $client->encours_max : null,
                'en_retard' => [
                    'nombre' => $ouvertes()->whereDate('date_echeance', '<', now()->toDateString())->count(),
                    'montant' => round((float) $ouvertes()->whereDate('date_echeance', '<', now()->toDateString())->sum(DB::raw('reste_a_payer * taux_change')), 2),
                ],
            ],
            'commandes' => [
                'en_cours' => ComClientEntete::where('client_id', $client->id)->whereIn('statut', ['en_attente', 'acceptee'])->count(),
                'nombre' => ComClientEntete::where('client_id', $client->id)->whereNotIn('statut', ['annulee', 'refusee'])->count(),
            ],
            'devis' => [
                'en_cours' => Devis::where('client_id', $client->id)->whereIn('statut', ['brouillon', 'envoye'])->count(),
                'nombre' => Devis::where('client_id', $client->id)->count(),
            ],
            'ventes_mensuelles' => $mensuel,
            'produits_les_plus_achetes' => $this->produitsLesPlusAchetes($client),
        ]);
    }

    /** Lignes des factures du client, de la plus récente à la plus ancienne, avec filtres et totaux. */
    public function historique(Request $request, int $id): JsonResponse
    {
        $client = Client::findOrFail($id);
        $filtres = $request->validate([
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
            'recherche' => ['nullable', 'string', 'max:100'],
            'produit_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $lignes = $this->lignesVendues($client)
            ->when($filtres['du'] ?? null, fn ($q, $du) => $q->whereDate('factures.date_facture', '>=', $du))
            ->when($filtres['au'] ?? null, fn ($q, $au) => $q->whereDate('factures.date_facture', '<=', $au))
            ->when($filtres['produit_id'] ?? null, fn ($q, $produit) => $q->where('facture_lignes.produit_id', $produit))
            ->when($filtres['recherche'] ?? null, fn ($q, $texte) => $q->where(fn ($q) => $q
                ->where('produits.numero', 'like', "%{$texte}%")
                ->orWhere('produits.description', 'like', "%{$texte}%")));

        // Totaux calculés avant le tri (requête d'agrégat sans ORDER BY, valable en MySQL strict).
        $totaux = (clone $lignes)->selectRaw('COALESCE(SUM(facture_lignes.quantite), 0) AS total_quantite, COALESCE(SUM(facture_lignes.montant_ht), 0) AS total_ht')->first();

        $page = $lignes->select([
            'facture_lignes.id', 'facture_lignes.produit_id', 'facture_lignes.quantite',
            'facture_lignes.prix_unitaire_ht', 'facture_lignes.remise_ligne', 'facture_lignes.montant_ht',
            'factures.id as facture_id', 'factures.numero as facture_numero', 'factures.date_facture',
            'produits.numero as produit_numero', 'produits.description as produit_description',
        ])
            ->orderByDesc('factures.date_facture')->orderByDesc('facture_lignes.id')
            ->paginate($filtres['per_page'] ?? 25)
            ->through(fn ($ligne) => [
                'id' => $ligne->id,
                'date' => Carbon::parse($ligne->date_facture)->toDateString(),
                'facture_id' => (int) $ligne->facture_id,
                'facture_numero' => $ligne->facture_numero,
                'produit_id' => (int) $ligne->produit_id,
                'produit_numero' => $ligne->produit_numero,
                'produit_description' => $ligne->produit_description,
                'quantite' => (int) $ligne->quantite,
                'prix_unitaire_ht' => (float) $ligne->prix_unitaire_ht,
                'remise_ligne' => (float) $ligne->remise_ligne,
                'montant_ht' => (float) $ligne->montant_ht,
            ]);

        return response()->json([
            ...$page->toArray(),
            'totaux' => ['quantite' => (int) $totaux->total_quantite, 'montant_ht' => round((float) $totaux->total_ht, 2)],
        ]);
    }

    public function categorie(Request $request, int $id): JsonResponse
    {
        $client = Client::findOrFail($id);
        $donnees = $request->validate(['categorie_id' => ['present', 'nullable', 'integer', TenantRule::exists('client_categories')]]);
        $client->update(['categorie_id' => $donnees['categorie_id']]);

        return response()->json($client->load('categorie:id,nom'));
    }

    public function ajouterFavori(Request $request, int $id): JsonResponse
    {
        $client = Client::findOrFail($id);
        ClientFavori::firstOrCreate(['user_id' => $request->user()->id, 'client_id' => $client->id]);

        return response()->json(['favori' => true]);
    }

    public function retirerFavori(Request $request, int $id): JsonResponse
    {
        $client = Client::findOrFail($id);
        ClientFavori::where('user_id', $request->user()->id)->where('client_id', $client->id)->delete();

        return response()->json(['favori' => false]);
    }

    /**
     * Lignes de factures valides du client. FactureLigne n'est pas cloisonnée : l'entreprise est filtrée
     * explicitement sur la facture, et le produit est joint dans la même entreprise (même supprimé).
     */
    private function lignesVendues(Client $client)
    {
        return FactureLigne::query()
            ->join('factures', 'factures.id', '=', 'facture_lignes.facture_id')
            ->leftJoin('produits', fn ($j) => $j->on('produits.id', '=', 'facture_lignes.produit_id')->on('produits.tenant_id', '=', 'factures.tenant_id'))
            ->where('factures.tenant_id', app(TenantContext::class)->getTenantId())
            ->where('factures.client_id', $client->id)
            ->whereIn('factures.statut', self::FACTURES_VALIDES);
    }

    /** Les 5 produits qui pèsent le plus dans les ventes au client. */
    private function produitsLesPlusAchetes(Client $client): array
    {
        $lignes = $this->lignesVendues($client)
            ->groupBy('facture_lignes.produit_id')
            ->selectRaw('facture_lignes.produit_id, SUM(facture_lignes.quantite) AS total_quantite, SUM(facture_lignes.montant_ht) AS total_ht, COUNT(DISTINCT factures.id) AS nb_factures, MAX(factures.date_facture) AS dernier_achat')
            ->orderByDesc('total_ht')
            ->limit(5)
            ->get();
        $produits = ProduitTenant::withTrashed()->whereIn('id', $lignes->pluck('produit_id'))->get(['id', 'numero', 'description'])->keyBy('id');

        return $lignes->map(fn ($ligne) => [
            'produit_id' => (int) $ligne->produit_id,
            'numero' => $produits->get($ligne->produit_id)?->numero,
            'description' => $produits->get($ligne->produit_id)?->description,
            'quantite' => (int) $ligne->total_quantite,
            'montant_ht' => round((float) $ligne->total_ht, 2),
            'factures' => (int) $ligne->nb_factures,
            'dernier_achat' => $ligne->dernier_achat ? Carbon::parse($ligne->dernier_achat)->toDateString() : null,
        ])->all();
    }
}
