<?php

namespace App\Services;

use App\Models\ComClientEntete;
use App\Models\ComFourEntete;
use App\Models\ProduitLien;
use App\Models\ProduitTenant;
use App\Models\ReliquatClient;
use App\Models\ScanTenant;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Disponibilité des produits (plan de parité fripv2, phase 1.4 : réservation de stock). PI compte son stock par les
 * scans : rien n'est décrémenté, la réservation se calcule.
 *
 * - Stock : la somme des scans du produit, comme les alertes de stock.
 * - Réservé : les quantités des commandes client acceptées (pas encore facturées), moins ce qui en est noté en
 *   reliquat (le client l'attendra du fournisseur).
 * - Disponible : stock moins réservé ; un disponible négatif est un manque.
 * - En commande : ce qui reste à recevoir des commandes fournisseur envoyées ou reçues en partie.
 * - Reliquats : ce que les clients attendent encore (reliquats inscrits, commandés ou reçus en partie).
 *
 * Ensembles (kits, phase 2.3) : un ensemble commandé réserve ses composants (ensembles imbriqués dépliés) ; son stock
 * et son disponible sont le nombre d'ensembles que permettent ceux de ses composants. Un produit en manque donne ses
 * équivalents et leur disponible.
 */
class DisponibiliteProduits
{
    public function __construct(private readonly LiensProduits $liens) {}

    /**
     * @param  list<int>|null  $produits  produits à calculer (tous les produits actifs sinon)
     * @param  bool  $avecEquivalents  donner les équivalents des produits en manque
     * @return Collection<int, array{produit_id: int, numero: string, description: string, mesure: ?string, stock: float, reserve: int|float, disponible: float, en_commande: int, reliquats: int|float, manque: float}>
     */
    public function calculer(?array $produits = null, bool $avecEquivalents = true): Collection
    {
        $fiches = ProduitTenant::query()->where('actif', true)
            ->when($produits !== null, fn ($q) => $q->whereIn('id', $produits))
            ->orderBy('numero')->get(['id', 'numero', 'description', 'mesure']);
        if ($fiches->isEmpty()) {
            return collect();
        }
        $ids = $fiches->pluck('id')->all();

        // Ensembles demandés et leurs composants ; ensembles dont les ventes réservent ces produits.
        $compositions = $this->liens->compositions($ids);
        $base = array_values(array_unique([...array_diff($ids, array_keys($compositions)), ...array_merge([], ...array_map('array_keys', array_values($compositions)))]));
        $reservants = array_values(array_unique([...$this->liens->ensemblesContenant($base), ...array_keys($compositions)]));
        $depliements = $this->liens->compositions($reservants);

        $numeros = ProduitTenant::query()->whereIn('id', $base)->pluck('numero', 'id');
        $stock = ScanTenant::query()->whereIn('numero', $numeros->values())->groupBy('numero')
            ->selectRaw('numero, SUM(quantite) AS quantite')->pluck('quantite', 'numero');
        $concernes = array_values(array_unique([...$base, ...$reservants]));
        // Lignes et reliquats dans une unité de vente (la caisse de 12) : comptés en unités de stock.
        $commandees = DB::table('com_client_ligne')->join('com_client_entete', 'com_client_entete.id', '=', 'com_client_ligne.com_entete_id')
            ->where('com_client_entete.tenant_id', $this->tenant())->where('com_client_entete.statut', 'acceptee')
            ->whereIn('com_client_ligne.produit_id', $concernes)
            ->groupBy('com_client_ligne.produit_id')->selectRaw('com_client_ligne.produit_id AS produit, SUM(com_client_ligne.quantite * COALESCE(com_client_ligne.facteur, 1)) AS quantite')
            ->pluck('quantite', 'produit');
        // Ce qui manque déjà sur ces commandes, noté en reliquat : le client l'attend du fournisseur, pas du stock.
        $enReliquat = ReliquatClient::query()->whereIn('produit_id', $concernes)->where('statut', '!=', ReliquatClient::ANNULE)
            ->whereIn('commande_client_id', ComClientEntete::query()->where('statut', 'acceptee')->select('id'))
            ->groupBy('produit_id')->selectRaw('produit_id, SUM(quantite * COALESCE(facteur, 1)) AS quantite')->pluck('quantite', 'produit_id');
        // Réservé de chaque produit commandé ; un ensemble réserve ses composants.
        $nets = [];
        foreach ($concernes as $id) {
            $nets[$id] = max(0, (float) ($commandees[$id] ?? 0) - (float) ($enReliquat[$id] ?? 0));
        }
        $reserves = [];
        foreach ($nets as $id => $quantite) {
            foreach (isset($depliements[$id]) ? $depliements[$id] : [$id => 1.0] as $composant => $parEnsemble) {
                $reserves[$composant] = ($reserves[$composant] ?? 0) + $quantite * $parEnsemble;
            }
        }

        $enCommande = DB::table('com_four_ligne')->join('com_four_entete', 'com_four_entete.id', '=', 'com_four_ligne.com_four_entete_id')
            ->where('com_four_entete.tenant_id', $this->tenant())
            ->whereIn('com_four_entete.statut', [ComFourEntete::STATUT_ENVOYEE, ComFourEntete::STATUT_PARTIELLE])
            ->whereIn('com_four_ligne.produit_id', $ids)
            // Reste à recevoir en unités d'achat, converti en unités de stock (une caisse de 12 attendue : 12).
            ->groupBy('com_four_ligne.produit_id')->selectRaw('com_four_ligne.produit_id AS produit, SUM(CASE WHEN com_four_ligne.quantite_commandee > com_four_ligne.quantite_recue THEN (com_four_ligne.quantite_commandee - com_four_ligne.quantite_recue) * com_four_ligne.qte_par_unite_achat ELSE 0 END) AS quantite')
            ->pluck('quantite', 'produit');
        $reliquats = ReliquatClient::query()->whereIn('produit_id', $ids)
            ->whereIn('statut', [ReliquatClient::CREE, ReliquatClient::COMMANDE, ReliquatClient::RECU_PARTIEL])
            ->groupBy('produit_id')->selectRaw('produit_id, SUM((quantite - quantite_recue) * COALESCE(facteur, 1)) AS quantite')->pluck('quantite', 'produit_id');

        // Stock et disponible d'un produit simple.
        $simple = function (int $id) use ($numeros, $stock, $reserves): array {
            $enStock = round((float) ($stock[$numeros[$id] ?? ''] ?? 0), 4);
            $reserve = self::nombre($reserves[$id] ?? 0);

            return [$enStock, $reserve, round($enStock - $reserve, 4)];
        };
        $lignes = $fiches->map(function (ProduitTenant $p) use ($compositions, $simple, $nets, $enCommande, $reliquats, $numeros) {
            if (isset($compositions[$p->id])) {
                // Ensemble : autant d'ensembles que ses composants en permettent.
                $composants = [];
                [$stockEnsembles, $disponibles] = [INF, INF];
                foreach ($compositions[$p->id] as $composant => $parEnsemble) {
                    [$enStock, , $disponible] = $simple($composant);
                    $stockEnsembles = min($stockEnsembles, floor($enStock / $parEnsemble + 1e-9));
                    $disponibles = min($disponibles, floor($disponible / $parEnsemble + 1e-9));
                    $composants[] = ['produit_id' => $composant, 'numero' => $numeros[$composant] ?? null, 'quantite' => $parEnsemble, 'disponible' => $disponible];
                }
                $disponible = (float) $disponibles;

                return [
                    'produit_id' => $p->id,
                    'numero' => $p->numero,
                    'description' => $p->description,
                    'mesure' => $p->mesure,
                    'stock' => (float) $stockEnsembles,
                    'reserve' => self::nombre($nets[$p->id] ?? 0),
                    'disponible' => $disponible,
                    'en_commande' => max(0, (int) ($enCommande[$p->id] ?? 0)),
                    'reliquats' => self::nombre(max(0, (float) ($reliquats[$p->id] ?? 0))),
                    'manque' => $disponible < 0 ? -$disponible : 0.0,
                    'ensemble' => true,
                    'composants' => $composants,
                ];
            }
            [$enStock, $reserve, $disponible] = $simple($p->id);

            return [
                'produit_id' => $p->id,
                'numero' => $p->numero,
                'description' => $p->description,
                'mesure' => $p->mesure,
                'stock' => $enStock,
                'reserve' => $reserve,
                'disponible' => $disponible,
                'en_commande' => max(0, (int) ($enCommande[$p->id] ?? 0)),
                'reliquats' => self::nombre(max(0, (float) ($reliquats[$p->id] ?? 0))),
                'manque' => $disponible < 0 ? -$disponible : 0.0,
            ];
        });

        return $avecEquivalents ? $this->avecEquivalents($lignes) : $lignes;
    }

    /** Les produits en manque donnent leurs équivalents (actifs) et leur disponible. */
    private function avecEquivalents(Collection $lignes): Collection
    {
        $enManque = $lignes->filter(fn (array $d) => $d['manque'] > 0)->pluck('produit_id')->all();
        if ($enManque === []) {
            return $lignes;
        }
        $liens = ProduitLien::query()->where('type', ProduitLien::EQUIVALENT)->whereIn('produit_id', $enManque)->get(['produit_id', 'lie_id', 'remarque']);
        if ($liens->isEmpty()) {
            return $lignes;
        }
        $disponibles = $this->calculer($liens->pluck('lie_id')->unique()->values()->all(), false)->keyBy('produit_id');

        return $lignes->map(function (array $d) use ($liens, $disponibles) {
            $equivalents = $liens->where('produit_id', $d['produit_id'])->filter(fn ($l) => isset($disponibles[$l->lie_id]))
                ->map(fn ($l) => [
                    'produit_id' => $l->lie_id, 'numero' => $disponibles[$l->lie_id]['numero'], 'description' => $disponibles[$l->lie_id]['description'],
                    'disponible' => $disponibles[$l->lie_id]['disponible'], 'remarque' => $l->remarque,
                ])->values()->all();

            return $equivalents === [] ? $d : $d + ['equivalents' => $equivalents];
        });
    }

    /**
     * Manques d'une commande qui vient d'être acceptée : pour chacun de ses produits dont le disponible (cette
     * commande comprise) est négatif, la quantité qui manque, au plus celle commandée.
     *
     * @return list<array{produit_id: int, numero: string, description: string, commande: int|float, disponible: float, manque: int}>
     */
    public function manquesDeCommande(ComClientEntete $commande): array
    {
        // En unités de stock : une ligne de 2 caisses de 12 compte pour 24.
        $parProduit = $commande->lignes->groupBy('produit_id')
            ->map(fn (Collection $lignes) => self::nombre($lignes->sum(fn ($l) => (float) $l->quantite * $l->facteurStock())));

        return $this->calculer($parProduit->keys()->all(), false)
            ->filter(fn (array $d) => $d['disponible'] < 0)
            ->map(fn (array $d) => [
                'produit_id' => $d['produit_id'],
                'numero' => $d['numero'],
                'description' => $d['description'],
                'commande' => $parProduit[$d['produit_id']],
                'disponible' => $d['disponible'],
                'manque' => (int) min($parProduit[$d['produit_id']], ceil($d['manque'])),
            ])->values()->all();
    }

    private function tenant(): ?int
    {
        return app(TenantContext::class)->getTenantId();
    }

    /** Une quantité entière reste un entier (comme avant les unités de vente) ; sinon quatre décimales. */
    private static function nombre(float $quantite): int|float
    {
        return abs($quantite - round($quantite)) < 1e-9 ? (int) round($quantite) : round($quantite, 4);
    }
}
