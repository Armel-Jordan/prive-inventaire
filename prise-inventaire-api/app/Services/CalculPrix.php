<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ListePrix;
use App\Models\ListePrixProduit;
use App\Models\ProduitTenant;
use App\Models\ReglePrix;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Prix de vente d'un produit pour un client (plan de parité fripv2, phase 1.2a).
 *
 * 1. Prix de la liste : la liste de prix du client si elle est active, sinon le prix de base du produit.
 * 2. Candidats : ce prix, la remise globale de la fiche client, et chaque règle en vigueur qui vise le
 *    client (ou sa liste), le produit (ou sa catégorie) et dont la quantité minimale est atteinte.
 * 3. Le prix retenu est le plus bas des candidats positifs, comme dans fripv2 (à égalité, le premier).
 */
final class CalculPrix
{
    /**
     * @param  array<int, array{produit_id: int, quantite: int}>  $lignes
     * @return array<int, array<string, mixed>>
     */
    public function calculer(?Client $client, array $lignes, CarbonInterface $date): array
    {
        $produits = ProduitTenant::withTrashed()->whereIn('id', array_column($lignes, 'produit_id'))->get()->keyBy('id');
        $liste = $client?->liste_prix_id ? ListePrix::where('actif', true)->find($client->liste_prix_id) : null;
        $prixSaisis = $liste
            ? ListePrixProduit::where('liste_prix_id', $liste->id)->whereIn('produit_id', $produits->keys())->pluck('prix', 'produit_id')
            : collect();
        $regles = $this->reglesPour($client, $date);

        return array_map(function (array $ligne) use ($client, $produits, $liste, $prixSaisis, $regles) {
            $produit = $produits->get($ligne['produit_id']);

            return $produit
                ? $this->calculerLigne($client, $produit, max(1, (int) $ligne['quantite']), $liste, $prixSaisis->get($produit->id), $regles)
                : ['produit_id' => (int) $ligne['produit_id'], 'quantite' => (int) $ligne['quantite'], 'introuvable' => true];
        }, $lignes);
    }

    /** Règles en vigueur qui peuvent concerner ce client (sans client : seulement les règles pour tous). */
    private function reglesPour(?Client $client, CarbonInterface $date): Collection
    {
        return ReglePrix::enVigueur($date)
            ->where(fn ($q) => $q->whereNull('client_id')->when($client, fn ($q) => $q->orWhere('client_id', $client->id)))
            ->where(fn ($q) => $q->whereNull('liste_prix_id')->when($client?->liste_prix_id, fn ($q) => $q->orWhere('liste_prix_id', $client->liste_prix_id)))
            ->orderBy('id')
            ->get();
    }

    private function calculerLigne(?Client $client, ProduitTenant $produit, int $quantite, ?ListePrix $liste, ?float $prixSaisi, Collection $regles): array
    {
        $prixBase = $produit->prix_unitaire !== null ? (float) $produit->prix_unitaire : null;
        $precision = $liste?->precision ?? 2;
        $prixListe = $liste ? $liste->prixPour($prixBase, $prixSaisi) : ($prixBase !== null ? round($prixBase, $precision) : null);

        $candidats = [[
            'origine' => $liste ? 'liste' : 'base',
            'libelle' => $liste?->nom,
            'prix' => $prixListe,
        ]];

        if ($prixListe !== null) {
            $remiseClient = (float) ($client?->taux_remise_global ?? 0);
            if ($remiseClient > 0) {
                $candidats[] = ['origine' => 'remise_client', 'libelle' => "{$remiseClient} %", 'prix' => round($prixListe * (1 - $remiseClient / 100), $precision)];
            }
        }

        foreach ($regles as $regle) {
            $vise = (! $regle->produit_id || (int) $regle->produit_id === (int) $produit->id)
                && (! $regle->categorie_produit || mb_strtolower((string) $produit->categorie) === mb_strtolower($regle->categorie_produit))
                && $quantite >= $regle->quantite_min;
            // Une remise suppose un prix de liste ; un prix fixe s'applique même sans prix de base.
            if ($vise && ($prixListe !== null || $regle->type === 'prix_fixe')) {
                $candidats[] = [
                    'origine' => 'regle',
                    'libelle' => $regle->libelle,
                    'regle_id' => $regle->id,
                    'prix' => round($regle->appliquer((float) $prixListe), $precision),
                ];
            }
        }

        // Le plus bas des prix positifs ; à égalité, le premier trouvé (liste, remise client, règles par ancienneté).
        $retenu = null;
        foreach ($candidats as $candidat) {
            if ($candidat['prix'] !== null && $candidat['prix'] > 0 && ($retenu === null || $candidat['prix'] < $retenu['prix'])) {
                $retenu = $candidat;
            }
        }
        $prix = $retenu['prix'] ?? $prixListe;

        return [
            'produit_id' => $produit->id,
            'quantite' => $quantite,
            'prix_base' => $prixBase,
            'liste' => $liste ? ['id' => $liste->id, 'nom' => $liste->nom] : null,
            'prix_liste' => $prixListe,
            'prix' => $prix,
            'remise_pourcentage' => $prixListe && $prix !== null && $prix < $prixListe ? round((1 - $prix / $prixListe) * 100, 2) : 0.0,
            'origine' => $retenu['origine'] ?? ($liste ? 'liste' : 'base'),
            'regle' => isset($retenu['regle_id']) ? ['id' => $retenu['regle_id'], 'libelle' => $retenu['libelle']] : null,
            'candidats' => $candidats,
        ];
    }
}
