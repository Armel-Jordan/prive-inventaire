<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\CaisseSession;
use App\Models\CarteCadeauMouvement;
use App\Models\Facture;
use App\Support\EntreprisePdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentPdf;

/**
 * Reçu d'une vente (ticket de caisse de 80 mm) : entreprise, numéro de facture, date et heure, caisse et vendeur,
 * produits, totaux avec les taxes, paiements (argent remis et monnaie rendue), reste dû. Il se réimprime à l'identique.
 */
class RecuVentePdf
{
    /** Largeur d'un ticket de 80 mm, en points. */
    private const LARGEUR = 226.77;

    public function __construct(private readonly DocumentsVentePdf $documents, private readonly DevisesVente $devises) {}

    /** @param  string  $fuseau  fuseau de l'heure imprimée (celui de l'utilisateur) */
    public function recu(Facture $facture, string $fuseau): DocumentPdf
    {
        $facture->loadMissing(['client', 'lignes.produit', 'paiements']);
        $entete = EntreprisePdf::entete();
        $paiements = $facture->paiements->sortBy('id')->values();
        $session = ($id = $paiements->pluck('caisse_session_id')->filter()->first()) ? CaisseSession::with('caisse')->find($id) : null;
        $vendeur = ($auteur = $paiements->pluck('enregistre_par')->filter()->first()) ? AdminUser::find($auteur)?->nom : null;
        $client = $facture->client?->de_passage ? $facture->passage_nom : $facture->client?->raison_sociale;
        $totaux = $this->documents->totaux('facture', $facture, $facture->lignes);
        // Hauteur du ticket selon son contenu (en points) : en-tête, deux lignes par produit, totaux, paiements.
        $monnaie = $paiements->contains(fn ($p) => $p->montant_recu !== null && (float) $p->montant_recu > (float) $p->montant);
        $hauteur = 200 + 28 * $facture->lignes->count() + 13 * count($totaux) + 13 * $paiements->count() + ($monnaie ? 26 : 0)
            + 11 * $paiements->whereNotNull('carte_cadeau_id')->count()
            + ((float) $facture->reste_a_payer > 0.004 ? 15 : 0) + ($client ? 11 : 0) + ($session || $vendeur ? 11 : 0);

        return Pdf::loadView('pdf.recu-vente', [
            'entreprise' => $entete['entreprise'],
            'symbole' => $facture->devise ? $this->devises->symbole($facture->devise) : $entete['devise'],
            'facture' => $facture,
            'client' => $client,
            'caisse' => $session?->caisse,
            'vendeur' => $vendeur,
            'paiements' => $paiements,
            // Solde restant sur chaque carte cadeau qui a payé, juste après son paiement.
            'soldesCartes' => CarteCadeauMouvement::whereIn('facture_paiement_id', $paiements->pluck('id'))->where('type', 'utilisation')->pluck('solde_apres', 'facture_paiement_id'),
            'totaux' => $totaux,
            'modes' => Caisses::LIBELLES_MODES,
            'instant' => $facture->created_at?->copy()->setTimezone($fuseau),
        ])->setPaper([0, 0, self::LARGEUR, $hauteur])->setOption('isFontSubsettingEnabled', true);
    }
}
