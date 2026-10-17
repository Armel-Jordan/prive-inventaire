<?php

namespace App\Taches;

use App\Models\TachePlanifiee;
use App\Services\ContratsLocation;
use App\Support\TenantContext;

/**
 * Facturation automatique des loyers : les loyers des contrats de location actifs dus à ce jour deviennent des
 * factures, une par contrat et par période. En brouillon, ou émises si le paramètre « emettre » est coché.
 * Désactivée tant que l'entreprise ne l'active pas.
 */
class FacturerLocations implements Tache
{
    public function __construct(private readonly ContratsLocation $contrats) {}

    public function executer(): string
    {
        $emettre = (bool) TachePlanifiee::parametreDe('facturation_locations', 'emettre');
        $resultat = $this->contrats->facturerLoyers(today(), null, $emettre, null, (int) app(TenantContext::class)->getTenantId());
        if ($resultat['factures'] === [] && $resultat['erreurs'] === []) {
            return 'Aucun loyer à facturer.';
        }
        $bilan = count($resultat['factures']).' facture(s) de loyer.';
        foreach ($resultat['erreurs'] as $erreur) {
            $bilan .= " Erreur ({$erreur['contrat']}, {$erreur['client']}) : {$erreur['message']}";
        }

        return $bilan;
    }
}
