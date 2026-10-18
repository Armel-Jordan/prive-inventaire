<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Configuration;
use App\Models\Consignation;
use App\Models\ConsignationMouvement;
use App\Models\Facture;
use App\Models\ProduitTenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Consignation chez le client (plan de parité fripv2, phase 1.9). De la marchandise déposée chez un client (prêt,
 * démonstration, stock de dépannage) reste à l'entreprise : le bon de dépôt ne porte aucun montant. Le relevé donne,
 * client par client, ce qu'il détient (solde), sa valeur au coût et la date du dernier dépôt. Deux sorties :
 *  - la reprise (le client rend : la marchandise revient à un lieu choisi) ;
 *  - la consommation (le client l'a utilisée : facture brouillon à ses prix, solde diminué aussitôt).
 * On ne sort jamais plus que le solde ; le solde est revérifié sous verrou au moment d'écrire. Le stock compté de PI
 * n'est pas touché : c'est un registre.
 */
class Consignations
{
    public function __construct(
        private readonly FacturesVente $factures,
        private readonly CalculPrix $prix,
        private readonly DevisesVente $devises,
        private readonly ValorisationInventaire $valorisation,
    ) {}

    /**
     * Bon de dépôt : numéroté, une ligne par produit (quantités d'un même produit additionnées), coût unitaire au dépôt
     * (coût moyen d'achat), un mouvement de dépôt par ligne.
     *
     * @param  array{client_id: int, date_depot: string, notes?: ?string, lignes: list<array{produit_id: int, quantite: int}>}  $donnees
     */
    public function deposer(array $donnees, ?int $auteur, int $tenantId): Consignation
    {
        $config = Configuration::pourEntite('consignation', $tenantId);
        if (! $config || ! $config->auto_increment) {
            throw ValidationException::withMessages(['numero' => __('La numérotation automatique des consignations doit être active.')]);
        }
        $quantites = collect($donnees['lignes'])->groupBy('produit_id')->map(fn (Collection $l) => (int) $l->sum('quantite'));
        $produits = ProduitTenant::withTrashed()->whereKey($quantites->keys())->get()->keyBy('numero');
        $couts = collect($this->valorisation->couts('cout_moyen', $produits))
            ->mapWithKeys(fn (array $c, string $numero) => [$produits[$numero]->id => $c['cout']]);

        return DB::transaction(function () use ($donnees, $quantites, $couts, $auteur, $tenantId) {
            $consignation = Consignation::create([
                'numero' => Configuration::consommerNumero('consignation', $tenantId),
                'client_id' => $donnees['client_id'],
                'date_depot' => $donnees['date_depot'],
                'notes' => $donnees['notes'] ?? null,
                'cree_par' => $auteur,
            ]);
            foreach ($quantites as $produitId => $quantite) {
                $cout = $couts[$produitId] ?? null;
                $consignation->lignes()->create(['produit_id' => $produitId, 'quantite' => $quantite, 'cout_unitaire' => $cout]);
                ConsignationMouvement::create([
                    'client_id' => $donnees['client_id'], 'produit_id' => $produitId, 'type' => 'depot', 'quantite' => $quantite, 'cout_unitaire' => $cout,
                    'date_mouvement' => $donnees['date_depot'].' '.now()->format('H:i:s'), 'consignation_id' => $consignation->id, 'cree_par' => $auteur,
                ]);
            }

            return $consignation->load('lignes');
        });
    }

    /**
     * Soldes consignés par client et produit (seulement ce qui reste, sauf $aussiNuls) : quantité, coût unitaire
     * (moyenne pondérée des dépôts), valeur au coût, date du dernier dépôt.
     *
     * @return Collection<int, array{client_id: int, produit_id: int, quantite: int, cout_unitaire: ?float, valeur: ?float, dernier_depot: ?string}>
     */
    public function soldes(?int $clientId = null, bool $aussiNuls = false): Collection
    {
        return ConsignationMouvement::query()->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->orderBy('date_mouvement')->get()
            ->groupBy(fn (ConsignationMouvement $m) => "{$m->client_id}|{$m->produit_id}")
            ->map(function (Collection $mouvements) {
                $depots = $mouvements->where('type', 'depot');
                $chiffres = $depots->filter(fn ($d) => $d->cout_unitaire !== null);
                $quantiteChiffree = $chiffres->sum('quantite');
                $cout = $quantiteChiffree > 0 ? round($chiffres->sum(fn ($d) => $d->quantite * (float) $d->cout_unitaire) / $quantiteChiffree, 4) : null;
                $quantite = (int) $mouvements->sum('quantite');

                return [
                    'client_id' => $mouvements->first()->client_id,
                    'produit_id' => $mouvements->first()->produit_id,
                    'quantite' => $quantite,
                    'cout_unitaire' => $cout,
                    'valeur' => $cout !== null ? round($quantite * $cout, 2) : null,
                    'dernier_depot' => $depots->max('date_mouvement')?->toDateString(),
                ];
            })
            ->filter(fn (array $s) => $aussiNuls || $s['quantite'] > 0)->values();
    }

    /** Reprise : le client rend des quantités (pas plus que son solde) ; elles reviennent au lieu choisi. */
    public function reprendre(int $clientId, array $lignes, string $lieu, ?int $auteur): int
    {
        return DB::transaction(function () use ($clientId, $lignes, $lieu, $auteur) {
            $quantites = $this->verifierSoldes($clientId, $lignes);
            foreach ($quantites as $produitId => $quantite) {
                ConsignationMouvement::create([
                    'client_id' => $clientId, 'produit_id' => $produitId, 'type' => 'retour', 'quantite' => -$quantite,
                    'date_mouvement' => now(), 'lieu_retour' => $lieu, 'cree_par' => $auteur,
                ]);
            }

            return (int) $quantites->sum();
        });
    }

    /**
     * Consommation : facture brouillon des quantités utilisées, aux prix du client (liste de prix, remise, règles),
     * dans sa devise ; le solde diminue aussitôt (mouvements reliés à la facture).
     */
    public function facturer(int $clientId, array $lignes, bool $emettre, ?int $auteur, int $tenantId): Facture
    {
        $config = Configuration::pourEntite('facture', $tenantId);
        if (! $config || ! $config->auto_increment) {
            throw ValidationException::withMessages(['facture' => __('La numérotation automatique des factures doit être active.')]);
        }

        return DB::transaction(function () use ($clientId, $lignes, $emettre, $auteur, $tenantId) {
            $quantites = $this->verifierSoldes($clientId, $lignes);
            $client = Client::withTrashed()->findOrFail($clientId);
            $devise = $this->devises->pourDocument(null, null, $client);
            $prix = collect($this->prix->calculer($client, $quantites->map(fn ($q, $p) => ['produit_id' => $p, 'quantite' => $q])->values()->all(), today()))
                ->keyBy('produit_id');
            $lignesFacture = $quantites->map(function (int $quantite, int $produitId) use ($prix, $devise) {
                if (($prix[$produitId]['prix'] ?? null) === null) {
                    $numero = ProduitTenant::withTrashed()->find($produitId)?->numero ?? "#{$produitId}";
                    throw ValidationException::withMessages(['lignes' => __('Aucun prix pour :numero : fixez son prix avant de facturer.', ['numero' => $numero])]);
                }
                $unitaire = (float) $prix[$produitId]['prix'];

                return [
                    'produit_id' => $produitId,
                    'quantite' => $quantite,
                    'prix_unitaire_ht' => $devise['devise'] && $devise['taux_change'] > 0 ? round($unitaire / $devise['taux_change'], 2) : round($unitaire, 2),
                    'description' => 'Marchandise en consignation consommée',
                ];
            })->values()->all();
            $facture = $this->factures->creer($devise + ['client_id' => $clientId, 'date_facture' => today()->toDateString(), 'lignes' => $lignesFacture], $tenantId);
            foreach ($quantites as $produitId => $quantite) {
                ConsignationMouvement::create([
                    'client_id' => $clientId, 'produit_id' => $produitId, 'type' => 'consommation', 'quantite' => -$quantite,
                    'date_mouvement' => now(), 'facture_id' => $facture->id, 'cree_par' => $auteur,
                ]);
            }
            if ($emettre) {
                $this->factures->emettre($facture, $auteur);
            }

            return $facture;
        });
    }

    /** Mouvements d'un client sur une période, du plus récent au plus ancien. */
    public function historique(int $clientId, CarbonInterface $du, CarbonInterface $au): Collection
    {
        return ConsignationMouvement::with(['produit:id,numero,description', 'consignation:id,numero', 'facture:id,numero'])
            ->where('client_id', $clientId)
            ->whereDate('date_mouvement', '>=', $du->toDateString())->whereDate('date_mouvement', '<=', $au->toDateString())
            ->orderByDesc('date_mouvement')->orderByDesc('id')->get();
    }

    /**
     * Quantités demandées par produit (additionnées, les zéros ignorés), contrôlées sous verrou contre le solde.
     *
     * @return Collection<int, int>
     */
    private function verifierSoldes(int $clientId, array $lignes): Collection
    {
        // Verrou sur le client : deux reprises ou facturations en même temps ne sortent pas deux fois le même stock.
        Client::withTrashed()->whereKey($clientId)->lockForUpdate()->first();
        $quantites = collect($lignes)->groupBy('produit_id')->map(fn (Collection $l) => (int) $l->sum('quantite'))->filter();
        if ($quantites->isEmpty()) {
            throw ValidationException::withMessages(['lignes' => __('Saisissez au moins une quantité.')]);
        }
        $soldes = $this->soldes($clientId, true)->keyBy('produit_id');
        foreach ($quantites as $produitId => $quantite) {
            $solde = $soldes[$produitId]['quantite'] ?? 0;
            if ($quantite > $solde) {
                $numero = ProduitTenant::withTrashed()->find($produitId)?->numero ?? "#{$produitId}";
                throw ValidationException::withMessages(['lignes' => __('Quantité trop grande pour :numero : le client en détient :solde.', ['numero' => $numero, 'solde' => $solde])]);
            }
        }

        return $quantites;
    }
}
