<?php

namespace App\Services;

use App\Models\ComClientEntete;
use App\Models\ComFourEntete;
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
 */
class DisponibiliteProduits
{
    /**
     * @param  list<int>|null  $produits  produits à calculer (tous les produits actifs sinon)
     * @return Collection<int, array{produit_id: int, numero: string, description: string, mesure: ?string, stock: float, reserve: int, disponible: float, en_commande: int, reliquats: int, manque: float}>
     */
    public function calculer(?array $produits = null): Collection
    {
        $fiches = ProduitTenant::query()->where('actif', true)
            ->when($produits !== null, fn ($q) => $q->whereIn('id', $produits))
            ->orderBy('numero')->get(['id', 'numero', 'description', 'mesure']);
        if ($fiches->isEmpty()) {
            return collect();
        }
        $ids = $fiches->pluck('id')->all();
        $stock = ScanTenant::query()->whereIn('numero', $fiches->pluck('numero'))->groupBy('numero')
            ->selectRaw('numero, SUM(quantite) AS quantite')->pluck('quantite', 'numero');
        $commandees = DB::table('com_client_ligne')->join('com_client_entete', 'com_client_entete.id', '=', 'com_client_ligne.com_entete_id')
            ->where('com_client_entete.tenant_id', $this->tenant())->where('com_client_entete.statut', 'acceptee')
            ->whereIn('com_client_ligne.produit_id', $ids)
            ->groupBy('com_client_ligne.produit_id')->selectRaw('com_client_ligne.produit_id AS produit, SUM(com_client_ligne.quantite) AS quantite')
            ->pluck('quantite', 'produit');
        // Ce qui manque déjà sur ces commandes, noté en reliquat : le client l'attend du fournisseur, pas du stock.
        $enReliquat = ReliquatClient::query()->whereIn('produit_id', $ids)->where('statut', '!=', ReliquatClient::ANNULE)
            ->whereIn('commande_client_id', ComClientEntete::query()->where('statut', 'acceptee')->select('id'))
            ->groupBy('produit_id')->selectRaw('produit_id, SUM(quantite) AS quantite')->pluck('quantite', 'produit_id');
        $enCommande = DB::table('com_four_ligne')->join('com_four_entete', 'com_four_entete.id', '=', 'com_four_ligne.com_four_entete_id')
            ->where('com_four_entete.tenant_id', $this->tenant())
            ->whereIn('com_four_entete.statut', [ComFourEntete::STATUT_ENVOYEE, ComFourEntete::STATUT_PARTIELLE])
            ->whereIn('com_four_ligne.produit_id', $ids)
            ->groupBy('com_four_ligne.produit_id')->selectRaw('com_four_ligne.produit_id AS produit, SUM(com_four_ligne.quantite_commandee - com_four_ligne.quantite_recue) AS quantite')
            ->pluck('quantite', 'produit');
        $reliquats = ReliquatClient::query()->whereIn('produit_id', $ids)
            ->whereIn('statut', [ReliquatClient::CREE, ReliquatClient::COMMANDE, ReliquatClient::RECU_PARTIEL])
            ->groupBy('produit_id')->selectRaw('produit_id, SUM(quantite - quantite_recue) AS quantite')->pluck('quantite', 'produit_id');

        return $fiches->map(function (ProduitTenant $p) use ($stock, $commandees, $enReliquat, $enCommande, $reliquats) {
            $enStock = round((float) ($stock[$p->numero] ?? 0), 4);
            $reserve = max(0, (int) ($commandees[$p->id] ?? 0) - (int) ($enReliquat[$p->id] ?? 0));
            $disponible = round($enStock - $reserve, 4);

            return [
                'produit_id' => $p->id,
                'numero' => $p->numero,
                'description' => $p->description,
                'mesure' => $p->mesure,
                'stock' => $enStock,
                'reserve' => $reserve,
                'disponible' => $disponible,
                'en_commande' => max(0, (int) ($enCommande[$p->id] ?? 0)),
                'reliquats' => max(0, (int) ($reliquats[$p->id] ?? 0)),
                'manque' => $disponible < 0 ? -$disponible : 0.0,
            ];
        });
    }

    /**
     * Manques d'une commande qui vient d'être acceptée : pour chacun de ses produits dont le disponible (cette
     * commande comprise) est négatif, la quantité qui manque, au plus celle commandée.
     *
     * @return list<array{produit_id: int, numero: string, description: string, commande: int, disponible: float, manque: int}>
     */
    public function manquesDeCommande(ComClientEntete $commande): array
    {
        $parProduit = $commande->lignes->groupBy('produit_id')->map(fn (Collection $lignes) => (int) $lignes->sum('quantite'));

        return $this->calculer($parProduit->keys()->all())
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
}
