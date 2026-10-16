<?php

namespace App\Taches;

use App\Jobs\EnvoyerCourriel;
use App\Models\TachePlanifiee;
use App\Services\Courriels\EnvoiCourriel;
use App\Services\Relances;
use Illuminate\Support\Carbon;

/**
 * Relances de paiement automatiques : un courriel de relance (modèle « relance », facture jointe) pour chaque
 * facture en retard de N jours au moins, dont la dernière relance date de N jours au moins, au plus
 * Relances::MAXIMUM_AUTOMATIQUES fois par facture et seulement si le client a une adresse de courriel. N : paramètre
 * « jours » de la tâche. Désactivée tant que l'entreprise ne l'active pas.
 */
class RelancerFacturesEnRetard implements Tache
{
    public function __construct(private readonly Relances $relances, private readonly EnvoiCourriel $envois) {}

    public function executer(): string
    {
        $jours = (int) TachePlanifiee::parametreDe('relances_paiement', 'jours');
        $envoyees = 0;
        foreach ($this->relances->aRelancerAutomatiquement($jours) as $facture) {
            $courriel = $this->envois->preparer('relance', $facture, 'fr');
            $envoi = $this->envois->creer('relance', $facture, [
                'langue' => 'fr',
                'destinataires' => $courriel['destinataires'],
                'objet' => $courriel['objet'],
                'contenu' => $courriel['contenu'],
            ], null);
            // Notée dès sa mise en file : la tâche suivante ne la renvoie pas pendant que le courriel part.
            $this->relances->noter($facture, 'courriel', Carbon::today()->toDateString(), null, null, $envoi->id, true);
            EnvoyerCourriel::dispatch($envoi->id);
            $envoyees++;
        }

        return $envoyees === 0 ? 'Aucune facture à relancer.' : "{$envoyees} relance(s) envoyée(s).";
    }
}
