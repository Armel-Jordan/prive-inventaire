<?php

namespace App\Services;

use App\Models\ComFourEntete;
use App\Models\ProduitTenant;
use App\Models\TenantParametres;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Valorisation de l'inventaire officiel (plan de parité fripv2, phase 2.6) : les quantités retenues par les
 * dernières prises d'inventaire validées de chaque secteur, multipliées par un coût unitaire au choix :
 *
 *  - dernier_achat : prix de la dernière commande fournisseur (envoyée, partielle ou reçue) du produit ;
 *  - cout_moyen    : coût moyen pondéré des quantités reçues (commandées si rien n'est encore reçu) ;
 *  - prix_vente    : prix unitaire de la fiche produit.
 *
 * Les prix d'achat sont ramenés à l'unité de stock (un carton de 12 coûte 12 unités) et à la devise de
 * l'entreprise (taux de change figé sur la commande). Rien n'est enregistré : c'est un état.
 */
class ValorisationInventaire
{
    public const METHODES = ['dernier_achat', 'cout_moyen', 'prix_vente'];

    public function __construct(private PrisesInventaire $prises) {}

    public function calculer(string $methode, ?int $secteurId = null): array
    {
        $lignes = $this->prises->inventaireOfficiel($secteurId);
        $produits = ProduitTenant::withTrashed()->whereIn('numero', $lignes->pluck('produit_numero')->unique()->values())
            ->get(['id', 'numero', 'description', 'categorie', 'prix_unitaire'])->keyBy('numero');
        $couts = $this->couts($methode, $produits);

        $parProduit = [];
        $parSecteur = [];
        foreach ($lignes as $ligne) {
            $numero = $ligne['produit_numero'];
            $cout = $couts[$numero] ?? null;
            $valeur = $cout ? round($ligne['quantite'] * $cout['cout'], 2) : null;

            $parProduit[$numero] ??= [
                'numero' => $numero,
                'description' => $ligne['description'],
                'categorie' => $produits[$numero]->categorie ?? null,
                'quantite' => 0.0,
                'cout_unitaire' => $cout['cout'] ?? null,
                'reference' => $cout['reference'] ?? null,
                'valeur' => $cout ? 0.0 : null,
                'secteurs' => [],
            ];
            $parProduit[$numero]['quantite'] = round($parProduit[$numero]['quantite'] + $ligne['quantite'], 4);
            if ($valeur !== null) {
                $parProduit[$numero]['valeur'] = round($parProduit[$numero]['valeur'] + $valeur, 2);
            }
            $parProduit[$numero]['secteurs'][] = $ligne['secteur'];

            $cle = $ligne['secteur_id'] ?? $ligne['secteur'];
            $parSecteur[$cle] ??= ['secteur_id' => $ligne['secteur_id'], 'secteur' => $ligne['secteur'], 'valeur' => 0.0, 'nb_produits' => 0, 'nb_sans_cout' => 0, 'prise' => $ligne['prise']];
            $parSecteur[$cle]['valeur'] = round($parSecteur[$cle]['valeur'] + ($valeur ?? 0), 2);
            $parSecteur[$cle]['nb_produits']++;
            $parSecteur[$cle]['nb_sans_cout'] += $cout ? 0 : 1;
        }
        $produitsValorises = collect($parProduit)->sortBy('numero')->values()
            ->map(fn (array $p) => ['secteurs' => array_values(array_unique($p['secteurs']))] + $p);

        return [
            'methode' => $methode,
            'devise' => (string) (TenantParametres::query()->value('devise_symbole') ?? ''),
            'total' => round($produitsValorises->sum(fn (array $p) => $p['valeur'] ?? 0), 2),
            'nb_produits' => $produitsValorises->count(),
            'nb_sans_cout' => $produitsValorises->whereNull('cout_unitaire')->count(),
            'produits' => $produitsValorises->all(),
            'secteurs' => collect($parSecteur)->sortBy('secteur')->values()->all(),
        ];
    }

    /**
     * Coût unitaire (unité de stock, devise de l'entreprise) par numéro de produit, avec sa référence. Sert aussi à
     * valoriser la marchandise en consignation chez les clients.
     *
     * @param  Collection<string, ProduitTenant>  $produits
     * @return array<string, array{cout: float, reference: ?string}>
     */
    public function couts(string $methode, Collection $produits): array
    {
        if ($methode === 'prix_vente') {
            return $produits->filter(fn (ProduitTenant $p) => $p->prix_unitaire !== null)
                ->map(fn (ProduitTenant $p) => ['cout' => round((float) $p->prix_unitaire, 4), 'reference' => null])->all();
        }

        $numeros = $produits->mapWithKeys(fn (ProduitTenant $p) => [$p->id => $p->numero]);
        $achats = ComFourEntete::query()
            ->join('com_four_ligne as l', 'l.com_four_entete_id', '=', 'com_four_entete.id')
            ->whereIn('l.produit_id', $numeros->keys())
            ->whereNotIn('com_four_entete.statut', [ComFourEntete::STATUT_BROUILLON, ComFourEntete::STATUT_ANNULEE])
            ->orderBy('com_four_entete.date_commande')->orderBy('com_four_entete.id')
            ->get(['l.produit_id', 'l.quantite_commandee', 'l.quantite_recue', 'l.qte_par_unite_achat', 'l.prix_unitaire',
                'com_four_entete.taux_change', 'com_four_entete.numero', 'com_four_entete.date_commande'])
            ->groupBy('produit_id');

        $couts = [];
        foreach ($achats as $produitId => $lignes) {
            $unitaire = fn ($l) => (float) $l->prix_unitaire * ((float) $l->taux_change ?: 1.0) / max(1, (int) $l->qte_par_unite_achat);
            if ($methode === 'dernier_achat') {
                $derniere = $lignes->last();
                $couts[$numeros[$produitId]] = [
                    'cout' => round($unitaire($derniere), 4),
                    'reference' => trim("{$derniere->numero} ".($derniere->date_commande ? Carbon::parse($derniere->date_commande)->format('d/m/Y') : '')),
                ];

                continue;
            }
            // Coût moyen pondéré par les quantités reçues (en unités de stock), sinon par les quantités commandées.
            $poids = fn ($l, string $champ) => (float) $l->{$champ} * max(1, (int) $l->qte_par_unite_achat);
            $champ = $lignes->sum(fn ($l) => $poids($l, 'quantite_recue')) > 0 ? 'quantite_recue' : 'quantite_commandee';
            $quantite = $lignes->sum(fn ($l) => $poids($l, $champ));
            if ($quantite > 0) {
                $couts[$numeros[$produitId]] = [
                    'cout' => round($lignes->sum(fn ($l) => $poids($l, $champ) * $unitaire($l)) / $quantite, 4),
                    'reference' => $lignes->count().' commande(s)',
                ];
            }
        }

        return $couts;
    }
}
