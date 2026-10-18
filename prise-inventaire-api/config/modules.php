<?php

/*
|--------------------------------------------------------------------------
| Modules fonctionnels (abonnement par entreprise)
|--------------------------------------------------------------------------
|
| Catalogue des grands modules fonctionnels de l'application, calqués sur les
| groupes de navigation. L'accès est décidé AU NIVEAU DE L'ENTREPRISE (tenant),
| au-dessus des permissions par utilisateur (rôles).
|
|  - core     : toujours actifs, non désactivables (produit de base).
|  - optional : pilotés par le plan de l'entreprise, ajustables par le super-admin.
|  - plans    : modules optionnels activés par défaut selon le plan.
|
*/

return [

    'core' => ['inventaire', 'parametres'],

    // ia : questions en langage naturel sur les rapports (Anthropic) ; activé par le super-admin seulement.
    'optional' => ['achats', 'ventes', 'finance', 'ia'],

    'labels' => [
        'inventaire' => 'Inventaire',
        'parametres' => 'Paramètres',
        'achats' => 'Achats',
        'ventes' => 'Ventes',
        'finance' => 'Finance',
        'ia' => 'Intelligence artificielle',
    ],

    'plans' => [
        // Noms de plans réels (cf. SuperAdminController::createTenant).
        'starter' => [],
        'pro' => ['achats', 'ventes'],
        'enterprise' => ['achats', 'ventes', 'finance'],
        // Plan par défaut historique de la table tenants.
        'basic' => [],
    ],
];
