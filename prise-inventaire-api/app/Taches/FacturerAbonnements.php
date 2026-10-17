<?php

namespace App\Taches;

use App\Models\TachePlanifiee;
use App\Services\AbonnementsClients;
use App\Support\TenantContext;

/**
 * Facturation automatique des abonnements clients : les échéances dues à ce jour deviennent des factures, une par
 * client et par date d'échéance. Elles naissent en brouillon, ou émises si le paramètre « emettre » est coché (sauf
 * les factures d'un abonnement marqué brouillon). Désactivée tant que l'entreprise ne l'active pas.
 */
class FacturerAbonnements implements Tache
{
    public function __construct(private readonly AbonnementsClients $abonnements) {}

    public function executer(): string
    {
        $emettre = (bool) TachePlanifiee::parametreDe('facturation_abonnements', 'emettre');
        $resultat = $this->abonnements->facturer(today(), null, $emettre, null, (int) app(TenantContext::class)->getTenantId());
        if ($resultat['factures'] === [] && $resultat['erreurs'] === []) {
            return 'Aucune échéance à facturer.';
        }
        $bilan = count($resultat['factures'])." facture(s) d'abonnement pour {$resultat['echeances']} échéance(s).";
        foreach ($resultat['erreurs'] as $erreur) {
            $bilan .= " Erreur ({$erreur['client']}, {$erreur['date']}) : {$erreur['message']}";
        }

        return $bilan;
    }
}
