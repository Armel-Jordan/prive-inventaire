<?php

namespace App\Taches;

use App\Models\Camion;
use App\Models\Notification;
use App\Models\TachePlanifiee;

/**
 * Prévient des contrôles techniques de camions qui expirent dans les N prochains jours (ou sont dépassés).
 * N : paramètre « jours » de la tâche. Un nouveau contrôle (nouvelle date) donne lieu à une nouvelle alerte.
 */
class SignalerControlesTechniques implements Tache
{
    public function executer(): string
    {
        $jours = (int) TachePlanifiee::parametreDe('controles_techniques', 'jours');

        $camions = Camion::where('actif', true)
            ->whereNotNull('date_controle_technique')
            ->whereDate('date_controle_technique', '<=', today()->addDays($jours)->toDateString())
            ->orderBy('date_controle_technique')
            ->get();

        $signales = 0;
        foreach ($camions as $camion) {
            $date = $camion->date_controle_technique;
            $etat = $date->isPast() && ! $date->isToday() ? 'est dépassé depuis le' : 'expire le';
            $alerte = Notification::alerterUneFois(
                'ct_expire', 'controle', 'Contrôle technique à renouveler',
                "Le contrôle technique du camion {$camion->immatriculation} {$etat} {$date->format('d/m/Y')}.",
                '/camions', ['controle' => "{$camion->id}:{$date->toDateString()}", 'camion_id' => $camion->id],
            );
            $signales += $alerte ? 1 : 0;
        }

        return $signales === 0 ? 'Aucun contrôle technique à signaler.' : "{$signales} contrôle(s) technique(s) signalé(s).";
    }
}
