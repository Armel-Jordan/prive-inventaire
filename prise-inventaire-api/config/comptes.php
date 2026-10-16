<?php

/*
 * Sécurité des comptes (plan de parité fripv2, phase 6.1) : mot de passe oublié, changement de mot de
 * passe et double authentification.
 */
return [
    // Adresse du site web (front) utilisée dans le lien de réinitialisation envoyé par courriel. Une origine
    // envoyée par le front n'est acceptée que si elle figure dans CORS_ALLOWED_ORIGINS (jamais « * »).
    'url_front' => env('FRONTEND_URL'),

    // Durée de validité du lien de réinitialisation.
    'reinitialisation_minutes' => (int) env('REINITIALISATION_MOT_DE_PASSE_MINUTES', 60),

    // Longueur minimale d'un mot de passe choisi par l'utilisateur (réinitialisation ou changement).
    'longueur_min_mot_de_passe' => 8,

    // Nom affiché dans l'application d'authentification (Google Authenticator, Microsoft Authenticator…).
    'deux_facteurs_emetteur' => env('DEUX_FACTEURS_EMETTEUR', env('APP_NAME', 'Prise Inventaire')),

    // Durée pour saisir le code après le mot de passe, et nombre d'essais permis.
    'deux_facteurs_defi_minutes' => 5,
    'deux_facteurs_essais' => 5,
];
