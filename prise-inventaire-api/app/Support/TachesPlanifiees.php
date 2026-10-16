<?php

namespace App\Support;

use App\Taches\ExecuterTransfertsPlanifies;
use App\Taches\NettoyerNotifications;
use App\Taches\SignalerAlertesStock;
use App\Taches\SignalerTransfertsImminents;

/**
 * Catalogue des tâches planifiées : traitements prédéfinis par l'application, avec leur réglage
 * par défaut. Chaque entreprise les active et les règle elle-même ; ajouter une tâche se fait ici.
 */
final class TachesPlanifiees
{
    public const RECURRENCES = ['horaire', 'quotidienne', 'hebdomadaire', 'mensuelle', 'une_fois'];

    /** code => [classe, récurrence par défaut, heure par défaut] */
    public const TACHES = [
        'transferts_planifies' => [ExecuterTransfertsPlanifies::class, 'horaire', '00:00'],
        'transferts_imminents' => [SignalerTransfertsImminents::class, 'quotidienne', '07:00'],
        'alertes_stock' => [SignalerAlertesStock::class, 'quotidienne', '07:00'],
        'nettoyage_notifications' => [NettoyerNotifications::class, 'quotidienne', '02:00'],
    ];

    public static function codes(): array
    {
        return array_keys(self::TACHES);
    }

    /** @return class-string<\App\Taches\Tache> */
    public static function classe(string $code): string
    {
        return self::TACHES[$code][0];
    }
}
