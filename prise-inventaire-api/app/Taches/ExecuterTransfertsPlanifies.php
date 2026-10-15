<?php

namespace App\Taches;

use App\Models\Notification;
use App\Models\TransfertPlanifie;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Exécute les transferts planifiés arrivés à échéance, comme le bouton « Exécuter », et prévient par notification. */
class ExecuterTransfertsPlanifies implements Tache
{
    public const EXECUTANT = 'Tâche planifiée';

    public function executer(): string
    {
        $dus = TransfertPlanifie::where('statut', 'planifie')
            ->where('date_planifiee', '<=', now())
            ->orderBy('date_planifiee')
            ->limit(500)
            ->get();

        $executes = 0;
        $echecs = [];
        foreach ($dus as $transfert) {
            try {
                DB::transaction(fn () => $transfert->executer(self::EXECUTANT));
                Notification::create_transfert_complete(
                    (string) ($transfert->produit_nom ?: $transfert->produit_numero),
                    (string) $transfert->secteur_destination,
                    (float) $transfert->quantite,
                    self::EXECUTANT,
                );
                $executes++;
            } catch (Throwable $erreur) {
                $echecs[] = "#{$transfert->id} : {$erreur->getMessage()}";
            }
        }

        $compteRendu = $executes === 0 && ! $echecs ? 'Aucun transfert à exécuter.' : "{$executes} transfert(s) exécuté(s).";
        if ($echecs) {
            throw new RuntimeException($compteRendu.' En échec : '.implode(' ; ', $echecs));
        }

        return $compteRendu;
    }
}
