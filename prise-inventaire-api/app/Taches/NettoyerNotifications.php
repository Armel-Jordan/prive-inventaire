<?php

namespace App\Taches;

use App\Models\Notification;

/** Supprime les notifications lues depuis plus de 30 jours. */
class NettoyerNotifications implements Tache
{
    public const JOURS = 30;

    public function executer(): string
    {
        $supprimees = Notification::where('lu', true)->where('created_at', '<', now()->subDays(self::JOURS))->delete();

        return "{$supprimees} notification(s) lue(s) supprimée(s).";
    }
}
