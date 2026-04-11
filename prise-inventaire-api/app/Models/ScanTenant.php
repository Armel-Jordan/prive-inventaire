<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScanTenant extends Model
{
    use SoftDeletes;

    protected $connection = 'mysql';

    protected $table = 'inventaire_scan';

    protected $fillable = [
        'tenant_id',
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
