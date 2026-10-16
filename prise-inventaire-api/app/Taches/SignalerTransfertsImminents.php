<?php

namespace App\Taches;

use App\Models\Notification;
use App\Models\TransfertPlanifie;

/** Prévient des transferts planifiés dans les prochaines 24 heures (une notification par transfert). */
class SignalerTransfertsImminents implements Tache
{
    public function executer(): string
    {
        $dejaSignales = Notification::withTrashed()->where('type', 'planification_imminente')->get()
            ->map(fn (Notification $n) => $n->data['transfert_id'] ?? null)->filter()->all();

        $imminents = TransfertPlanifie::where('statut', 'planifie')
            ->whereBetween('date_planifiee', [now(), now()->addDay()])
            ->whereNotIn('id', $dejaSignales)
            ->orderBy('date_planifiee')
            ->get();

        foreach ($imminents as $transfert) {
            Notification::create_planification_imminente(
                $transfert->id,
                (string) ($transfert->produit_nom ?: $transfert->produit_numero),
                $transfert->date_planifiee->format('d/m/Y H:i'),
            );
        }

        return $imminents->isEmpty() ? 'Aucun transfert imminent.' : $imminents->count().' transfert(s) imminent(s) signalé(s).';
    }
}
