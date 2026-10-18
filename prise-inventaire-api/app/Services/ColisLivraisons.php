<?php

namespace App\Services;

use App\Models\BonLivraison;
use App\Models\BonLivraisonLigne;
use App\Models\ColisLivraison;
use App\Models\ColisLivraisonLigne;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Colis des bons de livraison (plan de parité fripv2, phase 1.6 : « Préparation boîte »). La marchandise d'un bon se
 * range dans des colis numérotés d'après lui (BL-00005-001) : chacun reçoit des quantités de ses lignes, sans
 * dépasser ce qui reste à mettre en colis. Un colis dont toutes les quantités passent à zéro disparaît. Les colis se
 * préparent tant que le bon n'est pas parti en livraison. Le stock n'est pas touché : comme la préparation, c'est de
 * la logistique.
 */
class ColisLivraisons
{
    /** Statuts où les colis se préparent ou se modifient : avant le départ du camion (comme dans fripv2). */
    public const STATUTS = ['cree', 'en_preparation', 'pret'];

    /**
     * Quantités encore à mettre en colis, par ligne du bon : à livrer moins celles des colis (sauf un colis exclu, celui
     * qu'on modifie).
     *
     * @return array<int, int>
     */
    public function restants(BonLivraison $bon, ?int $saufColis = null): array
    {
        $bon->loadMissing('lignes');
        $emballe = ColisLivraisonLigne::query()
            ->whereIn('bon_livraison_ligne_id', $bon->lignes->pluck('id'))
            ->when($saufColis, fn ($q) => $q->where('colis_id', '!=', $saufColis))
            ->selectRaw('bon_livraison_ligne_id, SUM(quantite) as total')
            ->groupBy('bon_livraison_ligne_id')
            ->pluck('total', 'bon_livraison_ligne_id');

        return $bon->lignes->mapWithKeys(fn (BonLivraisonLigne $l) => [
            $l->id => max(0, (int) $l->quantite_a_livrer - (int) ($emballe[$l->id] ?? 0)),
        ])->all();
    }

    public function creer(BonLivraison $bon, array $donnees, ?int $auteur): ColisLivraison
    {
        $this->verifierStatut($bon);

        return DB::transaction(function () use ($bon, $donnees, $auteur) {
            // Verrou sur le bon : deux colis préparés en même temps ne dépassent pas les quantités.
            BonLivraison::whereKey($bon->id)->lockForUpdate()->first();
            $quantites = $this->quantites($bon, $donnees['lignes'], null);
            if ($quantites === []) {
                throw ValidationException::withMessages(['lignes' => __('Mettez au moins une quantité dans le colis.')]);
            }
            $colis = ColisLivraison::create([
                'bon_livraison_id' => $bon->id,
                'numero' => $this->numero($bon),
                'poids_kg' => $donnees['poids_kg'] ?? null,
                'notes' => $donnees['notes'] ?? null,
                'prepare_par' => $auteur,
            ]);
            foreach ($quantites as $ligne => $quantite) {
                $colis->lignes()->create(['bon_livraison_ligne_id' => $ligne, 'quantite' => $quantite]);
            }

            return $colis->load('lignes');
        });
    }

    /** Nouvelles quantités du colis ; toutes à zéro, il est supprimé (null). */
    public function modifier(ColisLivraison $colis, array $donnees): ?ColisLivraison
    {
        $bon = $colis->bonLivraison;
        $this->verifierStatut($bon);
        $this->verifierPasCharge($colis);

        return DB::transaction(function () use ($colis, $bon, $donnees) {
            BonLivraison::whereKey($bon->id)->lockForUpdate()->first();
            $quantites = $this->quantites($bon, $donnees['lignes'], $colis->id);
            if ($quantites === []) {
                $colis->delete();

                return null;
            }
            $colis->lignes()->whereNotIn('bon_livraison_ligne_id', array_keys($quantites))->delete();
            foreach ($quantites as $ligne => $quantite) {
                $colis->lignes()->updateOrCreate(['bon_livraison_ligne_id' => $ligne], ['quantite' => $quantite]);
            }
            $colis->update(array_intersect_key($donnees, array_flip(['poids_kg', 'notes'])));

            return $colis->load('lignes');
        });
    }

    public function supprimer(ColisLivraison $colis): void
    {
        $this->verifierStatut($colis->bonLivraison);
        $this->verifierPasCharge($colis);
        $colis->delete();
    }

    /** Colis du bon, lignes et quantités restantes : ce que l'écran affiche. */
    public function presenter(BonLivraison $bon): array
    {
        $bon->loadMissing(['lignes.produit' => fn ($q) => $q->withTrashed(), 'facture.client']);
        $restants = $this->restants($bon);

        return [
            'bon' => $bon->only(['id', 'numero', 'statut']) + [
                'client' => $bon->facture?->client?->de_passage && filled($bon->facture?->passage_nom) ? $bon->facture->passage_nom : $bon->facture?->client?->raison_sociale,
            ],
            'modifiable' => in_array($bon->statut, self::STATUTS, true),
            'lignes' => $bon->lignes->map(fn (BonLivraisonLigne $l) => [
                'id' => $l->id,
                'produit' => $l->produit?->only(['id', 'numero', 'description', 'mesure']),
                'quantite_a_livrer' => (int) $l->quantite_a_livrer,
                'restant' => $restants[$l->id] ?? 0,
            ])->values()->all(),
            'colis' => ColisLivraison::with('lignes')->where('bon_livraison_id', $bon->id)->orderBy('numero')->get(),
        ];
    }

    /** Quantités demandées, contrôlées (lignes du bon, sans dépasser le restant), par ligne ; les zéros sont retirés. */
    private function quantites(BonLivraison $bon, array $lignes, ?int $saufColis): array
    {
        $restants = $this->restants($bon, $saufColis);
        $quantites = [];
        foreach ($lignes as $ligne) {
            $id = (int) $ligne['bon_livraison_ligne_id'];
            $quantite = (int) $ligne['quantite'];
            if (! array_key_exists($id, $restants)) {
                throw ValidationException::withMessages(['lignes' => __("Une ligne n'appartient pas à ce bon.")]);
            }
            $quantites[$id] = ($quantites[$id] ?? 0) + $quantite;
            if ($quantites[$id] > $restants[$id]) {
                throw ValidationException::withMessages(['lignes' => __('Quantité trop grande : il reste :reste à mettre en colis sur cette ligne.', ['reste' => $restants[$id]])]);
            }
        }

        return array_filter($quantites);
    }

    /** Numéro du colis : celui du bon suivi d'un rang sur trois chiffres (BL-00005-001). */
    private function numero(BonLivraison $bon): string
    {
        $prefixe = $bon->numero.'-';
        $dernier = (int) ColisLivraison::where('numero', 'like', $prefixe.'%')->pluck('numero')
            ->map(fn (string $numero) => (int) substr($numero, strlen($prefixe)))->max();

        return $prefixe.str_pad((string) ($dernier + 1), 3, '0', STR_PAD_LEFT);
    }

    private function verifierStatut(BonLivraison $bon): void
    {
        if (! in_array($bon->statut, self::STATUTS, true)) {
            throw ValidationException::withMessages(['bon' => __('Les colis ne se modifient plus une fois le bon parti en livraison.')]);
        }
    }

    /** Un colis coché comme chargé dans le camion ne change plus : on le décoche d'abord (chargement de la tournée). */
    private function verifierPasCharge(ColisLivraison $colis): void
    {
        if ($colis->charge_le) {
            throw ValidationException::withMessages(['colis' => __('Le colis :numero est chargé dans le camion : décochez-le avant de le modifier.', ['numero' => $colis->numero])]);
        }
    }
}
