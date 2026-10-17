<?php

namespace App\Services;

use App\Models\BonLivraisonLigne;
use App\Models\TenantParametres;
use App\Models\Tournee;
use App\Models\TourneeBon;
use App\Support\EntreprisePdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentPdf;
use Illuminate\Support\Collection;

/**
 * Document de transport des matières dangereuses d'une tournée (plan de parité fripv2, phase 1.6 : le « rapport de
 * dangerosité » d'un camion) : les produits dangereux encore à livrer de ses bons, avec numéro ONU, classe, groupe
 * d'emballage et masse brute ; téléphone d'urgence et déclaration de l'entreprise (selon la réglementation de son
 * pays), signature du conducteur. Le document se remet au transporteur ; il ne bloque ni ne contrôle rien.
 */
class MatieresDangereusesPdf
{
    /** Déclaration par défaut, quand l'entreprise n'a pas saisi la sienne. */
    public const DECLARATION = "Je déclare que les marchandises dangereuses de cet envoi sont décrites ci-dessus de façon complète et exacte, qu'elles sont convenablement classées, emballées, marquées et étiquetées, et qu'elles sont à tous égards dans l'état voulu pour être transportées conformément à la réglementation applicable.";

    /**
     * Lignes dangereuses de la tournée, dans l'ordre de livraison : quantités préparées (sinon prévues) moins celles
     * déjà livrées, pour les bons non annulés.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function lignes(Tournee $tournee): Collection
    {
        $tournee->loadMissing([
            'tourneeBons.bonLivraison.facture.client',
            'tourneeBons.bonLivraison.lignes.produit' => fn ($q) => $q->withTrashed(),
        ]);

        return $tournee->tourneeBons->sortBy('ordre_livraison')->flatMap(function (TourneeBon $lien) {
            $bon = $lien->bonLivraison;
            if (! $bon || $bon->statut === 'annule') {
                return [];
            }

            return $bon->lignes->map(function (BonLivraisonLigne $ligne) use ($bon) {
                $produit = $ligne->produit;
                $quantite = max(0, (int) ($ligne->quantite_preparee ?: $ligne->quantite_a_livrer) - (int) $ligne->quantite_livree);
                if (! $produit?->estDangereux() || $quantite === 0) {
                    return null;
                }

                return [
                    'numero_onu' => $produit->numero_onu,
                    'designation' => $produit->description,
                    'classe' => $produit->classe_danger,
                    'groupe' => $produit->groupe_emballage,
                    'bon' => $bon->numero,
                    'client' => $bon->facture?->client?->raison_sociale,
                    'quantite' => $quantite,
                    // Unité de la ligne (la caisse de 12) ; la masse compte les unités de stock qu'elle contient.
                    'unite' => $ligne->unite ?? $produit->mesure,
                    'masse_kg' => $produit->poids_kg !== null ? round($quantite * $ligne->facteurStock() * (float) $produit->poids_kg, 2) : null,
                ];
            })->filter();
        })->values();
    }

    /** Données de la vue pdf.matieres-dangereuses. */
    public function donnees(Tournee $tournee, Collection $lignes): array
    {
        $parametres = TenantParametres::first();

        return [
            'entreprise' => EntreprisePdf::entete()['entreprise'],
            'tournee' => $tournee->loadMissing('camion'),
            'lignes' => $lignes,
            'masseTotale' => round((float) $lignes->sum('masse_kg'), 2),
            'masseIncomplete' => $lignes->contains(fn (array $l) => $l['masse_kg'] === null),
            'classes' => $lignes->pluck('classe')->filter()->unique()->sort()->values(),
            'telephoneUrgence' => $parametres?->md_telephone_urgence,
            'declaration' => $parametres?->md_declaration ?: self::DECLARATION,
        ];
    }

    public function pdf(Tournee $tournee, Collection $lignes): DocumentPdf
    {
        return Pdf::loadView('pdf.matieres-dangereuses', $this->donnees($tournee, $lignes))
            ->setPaper('A4', 'portrait')->setOption('isFontSubsettingEnabled', true);
    }
}
