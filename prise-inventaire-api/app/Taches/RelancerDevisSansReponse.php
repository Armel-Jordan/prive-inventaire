<?php

namespace App\Taches;

use App\Jobs\EnvoyerCourriel;
use App\Models\TachePlanifiee;
use App\Services\Courriels\EnvoiCourriel;
use App\Services\RelancesDevis;

/**
 * Relances automatiques des devis : un courriel de relance (modèle « relance_devis », devis joint, boutons de réponse
 * en ligne) pour chaque devis envoyé resté sans réponse depuis N jours au moins (depuis l'envoi ou la dernière
 * relance), encore valable, au plus RelancesDevis::MAXIMUM_AUTOMATIQUES fois et seulement si le client a une adresse
 * de courriel. N : paramètre « jours » de la tâche. Désactivée tant que l'entreprise ne l'active pas.
 */
class RelancerDevisSansReponse implements Tache
{
    public function __construct(private readonly RelancesDevis $relances, private readonly EnvoiCourriel $envois) {}

    public function executer(): string
    {
        $jours = (int) TachePlanifiee::parametreDe('relances_devis', 'jours');
        $envoyees = 0;
        foreach ($this->relances->aRelancerAutomatiquement($jours) as $devis) {
            $courriel = $this->envois->preparer('relance_devis', $devis, 'fr');
            $envoi = $this->envois->creer('relance_devis', $devis, [
                'langue' => 'fr',
                'destinataires' => $courriel['destinataires'],
                'objet' => $courriel['objet'],
                'contenu' => $courriel['contenu'],
            ], null);
            // Comptée à l'envoi réussi ; en file d'ici là, elle n'est pas renvoyée par la tâche suivante.
            EnvoyerCourriel::dispatch($envoi->id);
            $envoyees++;
        }

        return $envoyees === 0 ? 'Aucun devis à relancer.' : "{$envoyees} relance(s) de devis envoyée(s).";
    }
}
