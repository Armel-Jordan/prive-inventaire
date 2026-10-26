<?php

namespace App\Services;

use App\Models\ClientAdresse;
use App\Models\Tournee;
use App\Models\TourneeBon;
use App\Support\EntreprisePdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentPdf;
use Illuminate\Support\Collection;

/**
 * Feuille de route du livreur (plan de parité fripv2, phase 1.6 : « Séquences des routes », feuille du chauffeur) :
 * les arrêts de la tournée dans l'ordre de passage, avec client, adresse et instructions de livraison, téléphone,
 * quantités, et des cases à remplir (reçu par, remarques, kilomètres et heures).
 */
class FeuilleDeRoutePdf
{
    /**
     * Arrêts de la tournée dans l'ordre de passage. Adresse : celle de livraison principale de la fiche client, sinon
     * l'adresse de livraison (ou de facturation) du client ; client de passage : les coordonnées notées sur sa facture.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function arrets(Tournee $tournee): Collection
    {
        $tournee->loadMissing(['tourneeBons.bonLivraison.facture.client.adresses', 'tourneeBons.bonLivraison.lignes']);

        return $tournee->tourneeBons->sortBy('ordre_livraison')->map(function (TourneeBon $lien) {
            $bon = $lien->bonLivraison;
            $facture = $bon?->facture;
            $client = $facture?->client;
            $passage = $client?->de_passage && filled($facture?->passage_nom);
            // L'adresse « Livrer à » choisie sur le bon (socle S4.1), sinon celle de livraison principale du client.
            $livraison = ($bon?->adresse_livraison_id ? $client?->adresses?->firstWhere('id', $bon->adresse_livraison_id) : null)
                ?? $client?->adresses?->first(fn (ClientAdresse $a) => $a->livraison && $a->principale && $a->actif);
            $adresse = $passage
                ? $facture->passage_adresse
                : implode(', ', array_filter([$livraison?->adresse ?? ($client?->adresse_livraison ?: $client?->adresse_facturation), $livraison?->ville ?? $client?->ville]));

            return [
                'ordre' => (int) $lien->ordre_livraison,
                'bon' => $bon?->numero,
                'facture' => $facture?->numero,
                'client' => $passage ? $facture->passage_nom : $client?->raison_sociale,
                'sequence' => $client?->sequence_livraison,
                'adresse' => $adresse,
                'instructions' => $livraison?->instructions,
                'telephone' => $passage ? $facture->passage_telephone : ($client?->contact_telephone ?: $client?->telephone),
                'contact' => $passage ? null : $client?->contact_nom,
                'lignes' => $bon?->lignes->count() ?? 0,
                'quantite' => (int) ($bon?->lignes->sum(fn ($l) => (int) ($l->quantite_preparee ?: $l->quantite_a_livrer)) ?? 0),
            ];
        })->values();
    }

    /** Données de la vue pdf.feuille-de-route. */
    public function donnees(Tournee $tournee): array
    {
        return [
            'entreprise' => EntreprisePdf::entete()['entreprise'],
            'tournee' => $tournee->loadMissing(['camion', 'itineraire']),
            'arrets' => $this->arrets($tournee),
        ];
    }

    public function pdf(Tournee $tournee): DocumentPdf
    {
        return Pdf::loadView('pdf.feuille-de-route', $this->donnees($tournee))
            ->setPaper('A4', 'landscape')->setOption('isFontSubsettingEnabled', true);
    }
}
