<?php

/*
|--------------------------------------------------------------------------
| Intelligence artificielle (questions sur les rapports)
|--------------------------------------------------------------------------
|
| Les questions en langage naturel passent par l'API d'Anthropic (Claude). Sans clé, elles sont désactivées sur tout
| le serveur ; avec une clé, une entreprise s'en sert si le super-administrateur lui active le module « ia ».
| La clé reste dans l'environnement du serveur, jamais dans le dépôt.
|
*/

return [

    'cle' => env('ANTHROPIC_API_KEY'),

    'modele' => env('IA_MODELE', 'claude-opus-5-5'),

    'url' => env('IA_URL', 'https://api.anthropic.com/v1/messages'),

    // Délai d'un appel à l'API, en secondes.
    'delai' => (int) env('IA_DELAI', 120),

    // Échanges au plus (rapports exécutés) avant la réponse.
    'tours' => (int) env('IA_TOURS', 6),

    // Lignes d'un rapport transmises à l'IA ; les totaux portent sur toutes les lignes.
    'lignes' => (int) env('IA_LIGNES', 200),

    // Questions par compte et par jour.
    'questions_par_jour' => (int) env('IA_QUESTIONS_PAR_JOUR', 50),

];
