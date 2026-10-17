<?php

namespace App\Taches;

use App\Services\Comptabilite\Recurrences;

/**
 * Écritures récurrentes : les dates échues à ce jour des récurrences actives deviennent des écritures (loyer,
 * amortissement…). Une date refusée (période fermée, compte désactivé) reste à convertir et son motif est rapporté.
 * Désactivée tant que l'entreprise ne l'active pas : sans elle, les dates se convertissent depuis l'écran.
 */
class ConvertirEcrituresRecurrentes implements Tache
{
    public function __construct(private readonly Recurrences $recurrences) {}

    public function executer(): string
    {
        $resultat = $this->recurrences->convertir(null, today(), null);
        if ($resultat['converties'] === 0 && $resultat['erreurs'] === []) {
            return 'Aucune écriture récurrente à créer.';
        }
        $bilan = "{$resultat['converties']} écriture(s) récurrente(s) créée(s).";
        foreach ($resultat['erreurs'] as $erreur) {
            $bilan .= " Refus (récurrence n° {$erreur['recurrence']}, {$erreur['date']}) : {$erreur['message']}";
        }

        return $bilan;
    }
}
