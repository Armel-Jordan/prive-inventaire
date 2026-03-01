<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScanTenant extends Model
{
    protected $connection = 'mysql';
    protected $table = 'inventaire_scan';

    protected $fillable = [
        'numero',
        'type',
        'quantite',
        'unite_mesure',
        'employe',
        'secteur',
        'date_saisie',
        'scanneur',
    ];

    protected function casts(): array
    {
        return [
            'quantite' => 'decimal:4',
            'date_saisie' => 'datetime',
        ];
    }
}
