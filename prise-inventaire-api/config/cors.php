<?php

// Origines autorisées, pilotées par l'environnement (retire le '*' codé en dur).
// Prod : définir CORS_ALLOWED_ORIGINS=https://mon-domaine (et/ou un motif).
// Non défini => '*' (comportement historique, à restreindre en prod).
$origins = array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*')))));
$originPatterns = array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', '')))));

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins ?: ['*'],

    'allowed_origins_patterns' => $originPatterns,

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
