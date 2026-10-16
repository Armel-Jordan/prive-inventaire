<?php

namespace App\Taches;

use App\Models\ComFourEntete;
use App\Models\Notification;
use App\Models\TachePlanifiee;

/**
 * Signale les commandes fournisseur envoyées (ou partiellement reçues) dont la livraison prévue est dépassée de
 * plus de N jours. N : paramètre « jours » de la tâche (0 : dès le lendemain de la date prévue).
 */
class SignalerCommandesFournisseurEnRetard implements Tache
{
    public function executer(): string
    {
        $jours = (int) TachePlanifiee::parametreDe('commandes_fournisseur_en_retard', 'jours');
        $limite = today()->subDays($jours)->toDateString();

        $commandes = ComFourEntete::with('fournisseur:id,raison_sociale')
            ->whereIn('statut', [ComFourEntete::STATUT_ENVOYEE, ComFourEntete::STATUT_PARTIELLE])
            ->whereNotNull('date_livraison_prevue')
            ->whereDate('date_livraison_prevue', '<', $limite)
            ->orderBy('date_livraison_prevue')
            ->get();

        $signalees = 0;
        foreach ($commandes as $commande) {
            $fournisseur = $commande->fournisseur?->raison_sociale ?? 'fournisseur inconnu';
            $alerte = Notification::alerterUneFois(
                'commande_retard', 'commande_id', 'Commande fournisseur en retard',
                "La commande {$commande->numero} ({$fournisseur}) devait être livrée le {$commande->date_livraison_prevue->format('d/m/Y')}.",
                '/commandes-fournisseur', ['commande_id' => $commande->id, 'numero' => $commande->numero],
            );
            $signalees += $alerte ? 1 : 0;
        }

        return $signalees === 0 ? 'Aucune nouvelle commande en retard.' : "{$signalees} commande(s) fournisseur en retard signalée(s).";
    }
}
