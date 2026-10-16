<?php

namespace App\Taches;

/**
 * Traitement automatique exécuté par le planificateur, dans le contexte d'une entreprise.
 * Retourne un compte rendu court ; lève une exception en cas d'échec.
 */
interface Tache
{
    public function executer(): string;
}
