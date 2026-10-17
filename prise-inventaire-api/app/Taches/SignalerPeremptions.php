<?php

namespace App\Taches;

use App\Models\Lot;
use App\Models\Notification;
use App\Models\TachePlanifiee;

/**
 * Prévient des lots qui périment dans les N prochains jours (ou sont périmés) et dont il reste une quantité.
 * N : paramètre « jours » de la tâche. Une nouvelle date de péremption donne lieu à une nouvelle alerte.
 */
class SignalerPeremptions implements Tache
{
    public function executer(): string
    {
        $jours = (int) TachePlanifiee::parametreDe('peremptions', 'jours');

        $lots = Lot::query()->with('produit:id,numero,description,mesure')
            ->withSum('journal as solde', 'quantite')
            ->whereNotNull('date_peremption')
            ->whereDate('date_peremption', '<=', today()->addDays($jours)->toDateString())
            ->whereRaw('(SELECT COALESCE(SUM(m.quantite), 0) FROM lot_mouvements m WHERE m.lot_id = lots.id) > 0')
            ->orderBy('date_peremption')
            ->get();

        $signales = 0;
        foreach ($lots as $lot) {
            $date = $lot->date_peremption;
            $etat = $date->isPast() && ! $date->isToday() ? 'est périmé depuis le' : 'périme le';
            $quantite = rtrim(rtrim(number_format((float) $lot->solde, 4, ',', ' '), '0'), ',');
            $alerte = Notification::alerterUneFois(
                'peremption', 'peremption', 'Lot à écouler ou à retirer',
                "Le lot {$lot->numero} de {$lot->produit?->numero} ({$quantite} {$lot->produit?->mesure} restant) {$etat} {$date->format('d/m/Y')}.",
                '/lots', ['peremption' => "{$lot->id}:{$date->toDateString()}", 'lot_id' => $lot->id],
            );
            $signales += $alerte ? 1 : 0;
        }

        return $signales === 0 ? 'Aucun lot à signaler.' : "{$signales} lot(s) signalé(s).";
    }
}
