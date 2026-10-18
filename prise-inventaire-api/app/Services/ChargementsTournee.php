<?php

namespace App\Services;

use App\Models\BonLivraison;
use App\Models\BonLivraisonLigne;
use App\Models\ColisLivraison;
use App\Models\Tournee;
use App\Models\TourneeBon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Chargement du camion (plan de parité fripv2, phase 1.6 : « Chargement »). Avant le départ, on coche ce qui monte
 * dans le camion : chaque colis (à la main ou en scannant son étiquette) et, pour chaque arrêt, la marchandise qui
 * n'est dans aucun colis (le vrac). Les arrêts se chargent dans l'ordre inverse des livraisons : le dernier livré
 * part au fond du camion, le premier reste près des portes. Démarrer la tournée n'en dépend pas : l'écran prévient
 * seulement si le chargement commencé n'est pas fini.
 */
class ChargementsTournee
{
    /** État du chargement : arrêts dans l'ordre de chargement, leurs colis et leur vrac, et le décompte. */
    public function etat(Tournee $tournee): array
    {
        $liens = $this->liens($tournee);
        $colisParBon = ColisLivraison::with(['lignes', 'chargeur:id,nom'])
            ->whereIn('bon_livraison_id', $liens->pluck('bon_livraison_id'))
            ->orderBy('numero')->get()->groupBy('bon_livraison_id');

        $arrets = $liens->map(function (TourneeBon $lien) use ($colisParBon) {
            $bon = $lien->bonLivraison;
            $colis = $colisParBon->get($bon->id, collect());
            $lignes = $bon->lignes->keyBy('id');
            $emballe = [];
            foreach ($colis as $c) {
                foreach ($c->lignes as $l) {
                    $emballe[$l->bon_livraison_ligne_id] = ($emballe[$l->bon_livraison_ligne_id] ?? 0) + $l->quantite;
                }
            }
            $vrac = $bon->lignes->map(fn (BonLivraisonLigne $l) => [
                'produit' => $this->produit($l),
                'quantite' => max(0, $this->aCharger($l) - ($emballe[$l->id] ?? 0)),
            ])->filter(fn (array $v) => $v['quantite'] > 0)->values();

            return [
                'ordre' => (int) $lien->ordre_livraison,
                'bon_livraison_id' => $bon->id,
                'bon' => $bon->numero,
                'client' => $this->client($bon),
                'colis' => $colis->map(fn (ColisLivraison $c) => [
                    'id' => $c->id,
                    'numero' => $c->numero,
                    'poids_kg' => $c->poids_kg,
                    'contenu' => $c->lignes->map(fn ($l) => ['produit' => $this->produit($lignes->get($l->bon_livraison_ligne_id)), 'quantite' => $l->quantite])->values()->all(),
                    'charge_le' => $c->charge_le,
                    'charge_par' => $c->chargeur?->nom,
                ])->values()->all(),
                'vrac' => $vrac->all(),
                // Le vrac coché ne compte que s'il reste de la marchandise hors colis.
                'vrac_charge_le' => $vrac->isEmpty() ? null : $lien->vrac_charge_le,
                'vrac_charge_par' => $vrac->isEmpty() ? null : $lien->chargeurVrac?->nom,
            ];
        })->values();

        return [
            'tournee' => $tournee->only(['id', 'numero', 'statut']) + ['camion' => $tournee->camion?->immatriculation],
            'modifiable' => $tournee->statut === 'planifiee',
            'arrets' => $arrets->all(),
            'resume' => $this->resume($arrets),
        ];
    }

    /**
     * Décompte : colis et lots de vrac (un par arrêt qui en a), cochés ou non. « reste » : ce qui n'est pas coché ;
     * « commence » : au moins une chose cochée (l'écran ne prévient au départ que si le chargement a commencé ou si
     * la tournée a des colis).
     */
    public function resume(Collection $arrets): array
    {
        $colis = $arrets->sum(fn (array $a) => count($a['colis']));
        $colisCharges = $arrets->sum(fn (array $a) => collect($a['colis'])->whereNotNull('charge_le')->count());
        $vrac = $arrets->filter(fn (array $a) => $a['vrac'] !== [])->count();
        $vracCharges = $arrets->filter(fn (array $a) => $a['vrac'] !== [] && $a['vrac_charge_le'] !== null)->count();

        return [
            'colis' => $colis,
            'colis_charges' => $colisCharges,
            'vrac' => $vrac,
            'vrac_charges' => $vracCharges,
            'reste' => ($colis - $colisCharges) + ($vrac - $vracCharges),
            'commence' => $colisCharges + $vracCharges > 0,
        ];
    }

    /** Coche (ou décoche) un colis comme chargé ; il doit appartenir à un arrêt de la tournée. */
    public function chargerColis(Tournee $tournee, ColisLivraison $colis, bool $charge, ?int $auteur): void
    {
        $this->verifierStatut($tournee);
        if (! $this->liens($tournee)->contains('bon_livraison_id', $colis->bon_livraison_id)) {
            throw ValidationException::withMessages(['colis' => __("Le colis :numero n'est pas dans cette tournée.", ['numero' => $colis->numero])]);
        }
        // Déjà chargé : on garde le premier pointage.
        if ($charge && $colis->charge_le) {
            return;
        }
        $colis->update(['charge_le' => $charge ? now() : null, 'charge_par' => $charge ? $auteur : null]);
    }

    /**
     * Étiquette scannée (ou numéro saisi) : le colis est coché comme chargé.
     *
     * @return array{ColisLivraison, bool} le colis, et s'il était déjà chargé
     */
    public function scanner(Tournee $tournee, string $numero, ?int $auteur): array
    {
        $numero = trim($numero);
        $colis = ColisLivraison::where('numero', $numero)->first();
        if (! $colis) {
            throw ValidationException::withMessages(['numero' => __('Colis inconnu : :numero.', ['numero' => $numero])]);
        }
        $deja = $colis->charge_le !== null;
        $this->chargerColis($tournee, $colis, true, $auteur);

        return [$colis, $deja];
    }

    /** Coche (ou décoche) le vrac d'un arrêt : la marchandise du bon qui n'est dans aucun colis. */
    public function chargerVrac(Tournee $tournee, int $bonId, bool $charge, ?int $auteur): void
    {
        $this->verifierStatut($tournee);
        $lien = $this->liens($tournee)->firstWhere('bon_livraison_id', $bonId);
        if (! $lien) {
            throw ValidationException::withMessages(['bon_livraison_id' => __("Ce bon n'est pas dans cette tournée.")]);
        }
        if ($charge && $lien->vrac_charge_le) {
            return;
        }
        $lien->update(['vrac_charge_le' => $charge ? now() : null, 'vrac_charge_par' => $charge ? $auteur : null]);
    }

    /** Les colis de bons sortis de la tournée (bon retiré, tournée supprimée) ne sont plus dans le camion. */
    public function decharger(array $bonIds): void
    {
        ColisLivraison::whereIn('bon_livraison_id', $bonIds)->whereNotNull('charge_le')->update(['charge_le' => null, 'charge_par' => null]);
    }

    /** Arrêts de la tournée (sans les bons annulés), du dernier livré au premier : l'ordre de chargement. */
    private function liens(Tournee $tournee): Collection
    {
        $tournee->loadMissing([
            'camion', 'tourneeBons.chargeurVrac:id,nom', 'tourneeBons.bonLivraison.facture.client',
            'tourneeBons.bonLivraison.lignes.produit' => fn ($q) => $q->withTrashed(),
        ]);

        return $tournee->tourneeBons
            ->filter(fn (TourneeBon $l) => $l->bonLivraison && $l->bonLivraison->statut !== 'annule')
            ->sortByDesc('ordre_livraison')->values();
    }

    /** Quantité d'une ligne qui monte dans le camion : la quantité préparée (sinon à livrer), moins le déjà livré. */
    private function aCharger(BonLivraisonLigne $ligne): int
    {
        return max(0, (int) ($ligne->quantite_preparee ?: $ligne->quantite_a_livrer) - (int) $ligne->quantite_livree);
    }

    private function produit(?BonLivraisonLigne $ligne): ?array
    {
        return $ligne?->produit?->only(['id', 'numero', 'description']);
    }

    private function client(BonLivraison $bon): ?string
    {
        $facture = $bon->facture;

        return $facture?->client?->de_passage && filled($facture->passage_nom) ? $facture->passage_nom : $facture?->client?->raison_sociale;
    }

    private function verifierStatut(Tournee $tournee): void
    {
        if ($tournee->statut !== 'planifiee') {
            throw ValidationException::withMessages(['tournee' => __('Le chargement se coche avant le départ de la tournée.')]);
        }
    }
}
