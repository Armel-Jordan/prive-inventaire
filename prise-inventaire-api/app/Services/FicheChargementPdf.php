<?php

namespace App\Services;

use App\Models\Tournee;
use App\Support\EntreprisePdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentPdf;

/**
 * Fiche de chargement du camion (plan de parité fripv2, phase 1.6 : « Chargement ») : ce qui monte dans le camion,
 * arrêt par arrêt dans l'ordre de chargement (le dernier livré d'abord), colis par colis puis le vrac, avec une case
 * à cocher (ou l'heure du pointage) et les signatures du chargeur et du chauffeur.
 */
class FicheChargementPdf
{
    public function __construct(private readonly ChargementsTournee $chargements) {}

    /** Données de la vue pdf.fiche-chargement. */
    public function donnees(Tournee $tournee): array
    {
        $etat = $this->chargements->etat($tournee);
        $poids = collect($etat['arrets'])->flatMap(fn (array $a) => $a['colis'])->sum(fn (array $c) => (float) ($c['poids_kg'] ?? 0));

        return [
            'entreprise' => EntreprisePdf::entete()['entreprise'],
            'tournee' => $tournee->loadMissing(['camion', 'itineraire']),
            'arrets' => $etat['arrets'],
            'resume' => $etat['resume'],
            'poids' => $poids,
        ];
    }

    public function pdf(Tournee $tournee): DocumentPdf
    {
        return Pdf::loadView('pdf.fiche-chargement', $this->donnees($tournee))
            ->setPaper('A4')->setOption('isFontSubsettingEnabled', true);
    }
}
