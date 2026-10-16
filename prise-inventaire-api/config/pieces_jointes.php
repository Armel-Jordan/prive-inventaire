<?php

/*
 * Pièces jointes des fiches et documents (plan de parité fripv2, phase 0.7).
 */
return [

    // Disque de config/filesystems.php : « local » (storage/app/private) par défaut, « s3 » une fois
    // le paquet league/flysystem-aws-s3-v3 installé et les variables AWS_* renseignées.
    'disque' => env('PIECES_JOINTES_DISQUE', 'local'),

    // Taille maximale d'un fichier, en kilo-octets (20 Mo par défaut). Les limites PHP
    // upload_max_filesize et post_max_size doivent être au moins aussi grandes.
    'taille_max_ko' => (int) env('PIECES_JOINTES_TAILLE_MAX_KO', 20480),

    // Extensions refusées : exécutables et scripts.
    'extensions_refusees' => [
        'exe', 'com', 'bat', 'cmd', 'msi', 'scr', 'dll', 'ps1', 'vbs', 'js', 'jar', 'sh', 'php', 'phtml', 'iso', 'bkp',
    ],

];
