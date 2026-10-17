<?php

namespace App\Taches;

use App\Services\ContratsLocation;
use App\Support\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Renouvellement automatique des contrats de location : chaque contrat actif marqué « renouvellement automatique »,
 * pas encore renouvelé et arrivé à sa fin, donne le contrat suivant (prix augmentés de ses taux) et la facture
 * brouillon de ses prix de contrat. Désactivée tant que l'entreprise ne l'active pas.
 */
class RenouvelerContrats implements Tache
{
    public function __construct(private readonly ContratsLocation $contrats) {}

    public function executer(): string
    {
        $tenantId = (int) app(TenantContext::class)->getTenantId();
        $nouveaux = [];
        $erreurs = [];
        foreach ($this->contrats->aRenouveler(today()) as $contrat) {
            try {
                $nouveaux[] = "{$contrat->numero} → ".$this->contrats->renouveler($contrat, true, null, $tenantId)->numero;
            } catch (ValidationException $e) {
                $erreurs[] = "{$contrat->numero} : ".(collect($e->errors())->flatten()->first() ?? $e->getMessage());
            }
        }
        if ($nouveaux === [] && $erreurs === []) {
            return 'Aucun contrat à renouveler.';
        }

        return trim(($nouveaux ? count($nouveaux).' contrat(s) renouvelé(s) : '.implode(', ', $nouveaux).'.' : '')
            .($erreurs ? ' Erreurs : '.implode(' ; ', $erreurs) : ''));
    }
}
