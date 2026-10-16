<?php

namespace App\Taches;

use App\Models\BonLivraison;
use App\Models\Notification;
use App\Models\TachePlanifiee;

/**
 * Signale les bons de livraison encore à livrer (créés, en préparation, prêts ou en livraison) plus de N jours
 * après leur création. N : paramètre « jours » de la tâche.
 */
class SignalerLivraisonsEnRetard implements Tache
{
    public function executer(): string
    {
        $jours = (int) TachePlanifiee::parametreDe('livraisons_en_retard', 'jours');

        $bons = BonLivraison::with('facture.client:id,raison_sociale')
            ->whereIn('statut', ['cree', 'en_preparation', 'pret', 'en_livraison'])
            ->where('created_at', '<', now()->subDays($jours))
            ->orderBy('created_at')
            ->get();

        $signales = 0;
        foreach ($bons as $bon) {
            $client = $bon->facture?->client?->raison_sociale ?? 'client inconnu';
            $alerte = Notification::alerterUneFois(
                'livraison_retard', 'bon_id', 'Livraison en retard',
                "Le bon de livraison {$bon->numero} ({$client}), créé le {$bon->created_at->format('d/m/Y')}, n'est pas encore livré.",
                '/bons-livraison', ['bon_id' => $bon->id, 'numero' => $bon->numero],
            );
            $signales += $alerte ? 1 : 0;
        }

        return $signales === 0 ? 'Aucune nouvelle livraison en retard.' : "{$signales} livraison(s) en retard signalée(s).";
    }
}
