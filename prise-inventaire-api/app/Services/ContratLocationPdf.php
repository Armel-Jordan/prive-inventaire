<?php

namespace App\Services;

use App\Models\ContratLocation;
use App\Support\EntreprisePdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentPdf;

/**
 * Contrat de location imprimé (plan de parité fripv2, phase 1.8 : « Rapport contrat ») : parties, durée et rythme des
 * loyers, produits loués (quantités, prix de contrat, loyer par période), totaux et signatures.
 */
class ContratLocationPdf
{
    public function __construct(private readonly ContratsLocation $contrats) {}

    /** Données de la vue pdf.contrat-location. */
    public function donnees(ContratLocation $contrat): array
    {
        $contrat->loadMissing(['client', 'type', 'lignes.produit']);

        return [
            'entreprise' => EntreprisePdf::entete()['entreprise'],
            'contrat' => $contrat,
            'devise' => $contrat->client?->devise ?: app(DevisesVente::class)->reference(),
            'loyer' => $this->contrats->loyer($contrat),
            'total_contrat' => round($contrat->lignes->sum(fn ($l) => $l->quantite * (float) $l->prix_contrat), 2),
            'frequence' => ['mensuelle' => 'mois', 'trimestrielle' => 'trimestre', 'semestrielle' => 'semestre', 'annuelle' => 'an'][$contrat->frequence] ?? $contrat->frequence,
        ];
    }

    public function pdf(ContratLocation $contrat): DocumentPdf
    {
        return Pdf::loadView('pdf.contrat-location', $this->donnees($contrat))
            ->setPaper('A4')->setOption('isFontSubsettingEnabled', true);
    }
}
