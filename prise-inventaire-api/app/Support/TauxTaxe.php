<?php

namespace App\Support;

use App\Models\ComClientLigne;
use App\Models\TenantParametres;

/**
 * Taux de taxe par défaut de l'entreprise : celui des paramètres (Configuration › Devise & TVA), que la taxe
 * marquée « par défaut » met à jour. Il s'applique aux lignes sans taux ni groupe de taxes. Les documents
 * prenaient 20 % en dur, même pour une entreprise à 19,25 % (Cameroun) ou 18 % (Sénégal).
 */
final class TauxTaxe
{
    /** Taux par défaut de l'entreprise courante, 20 % si elle n'en a pas encore réglé. */
    public static function parDefaut(): float
    {
        $taux = TenantParametres::query()->value('tva_taux');

        return $taux !== null ? (float) $taux : (float) ComClientLigne::TAUX_TVA_DEFAUT;
    }

    /** Fait du taux d'une taxe le taux par défaut de l'entreprise (taxe marquée « par défaut »). */
    public static function definir(int $tenantId, float $taux): void
    {
        TenantParametres::updateOrCreate(['tenant_id' => $tenantId], ['tva_taux' => $taux]);
    }
}
