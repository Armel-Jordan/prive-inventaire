<?php

/*
 * Espace client (portail des clients des entreprises), servi par son propre site.
 */
return [
    // Adresse du site de l'espace client, utilisée dans les liens des courriels (invitation, mot de passe
    // oublié). Vide : l'adresse transmise par le back-office de l'entreprise (écran Espace client).
    'url' => env('ESPACE_CLIENT_URL'),
];
