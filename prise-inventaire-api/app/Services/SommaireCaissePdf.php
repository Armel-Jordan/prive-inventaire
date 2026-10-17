<?php

namespace App\Services;

use App\Models\CaisseSession;
use App\Support\EntreprisePdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentPdf;

/**
 * Sommaire de caisse d'une session fermée : par mode de paiement, le fond, les encaissements, les entrées et sorties,
 * l'attendu, le compté et l'écart figés à la fermeture ; puis chaque opération, les encaissements par employé et
 * ceux faits hors caisse pendant la session.
 */
class SommaireCaissePdf
{
    public function __construct(private readonly Caisses $caisses, private readonly DevisesVente $devises) {}

    /** @param  string  $fuseau  fuseau des heures imprimées (celui de l'utilisateur) */
    public function sommaire(CaisseSession $session, string $fuseau): DocumentPdf
    {
        $entete = EntreprisePdf::entete();
        $sommaire = $this->caisses->sommaire($session);
        // Symbole de chaque devise rencontrée (null : celle de l'entreprise).
        $symboles = ['' => $entete['devise']];
        foreach ([...$sommaire['lignes'], ...$sommaire['operations'], ...$sommaire['sans_caisse']['operations']] as $element) {
            if ($element['devise'] !== null) {
                $symboles[$element['devise']] ??= $this->devises->symbole($element['devise']);
            }
        }

        return Pdf::loadView('pdf.sommaire-caisse', [
            'entreprise' => $entete['entreprise'],
            'sommaire' => $sommaire,
            'symboles' => $symboles,
            'modes' => Caisses::LIBELLES_MODES,
            'types' => Caisses::LIBELLES_TYPES,
            'fuseau' => $fuseau,
            'genere_le' => now($fuseau),
        ])->setPaper('A4', 'landscape')->setOption('isFontSubsettingEnabled', true);
    }
}
