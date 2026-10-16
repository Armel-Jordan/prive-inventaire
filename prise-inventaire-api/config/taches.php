<?php

/*
 * Tâches planifiées (plan de parité fripv2, phase 0.10).
 */
return [

    // Fuseau des heures d'exécution réglées par les entreprises (ex. Africa/Douala, America/Montreal).
    // Vide : fuseau de l'application.
    'fuseau' => env('TACHES_FUSEAU'),

    // Sans planificateur (cron « schedule:run »), les tâches dues s'exécutent après la réponse d'une
    // requête, au plus une fois par minute. Mettre à false une fois le planificateur en place.
    'declenchement_requetes' => (bool) env('TACHES_DECLENCHEMENT_REQUETES', true),

];
