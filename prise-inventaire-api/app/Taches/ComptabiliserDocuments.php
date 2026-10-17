<?php

namespace App\Taches;

use App\Services\Comptabilite\Comptabilisation;
use DomainException;

/**
 * Comptabilisation automatique : les ventes, les achats et les paiements datés de la date de début à aujourd'hui
 * passent au journal, et les écritures des documents annulés ou supprimés sont contre-passées. Un document refusé
 * (période fermée, compte non réglé) attend et son motif est rapporté. Désactivée tant que l'entreprise ne l'active pas.
 */
class ComptabiliserDocuments implements Tache
{
    public function __construct(private readonly Comptabilisation $comptabilisation) {}

    public function executer(): string
    {
        try {
            $resultat = $this->comptabilisation->comptabiliser(today()->setDate(1900, 1, 1), today(), null);
        } catch (DomainException $erreur) {
            return $erreur->getMessage();
        }
        if ($resultat['creees'] === 0 && $resultat['contre_passations'] === 0 && $resultat['erreurs'] === []) {
            return 'Aucun document à comptabiliser.';
        }
        $bilan = "{$resultat['creees']} écriture(s) créée(s), {$resultat['contre_passations']} contre-passation(s).";
        foreach ($resultat['erreurs'] as $erreur) {
            $bilan .= " Refus ({$erreur['document']}) : {$erreur['message']}";
        }

        return $bilan;
    }
}
