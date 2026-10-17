<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScanTenant extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $connection = 'mysql';

    protected $table = 'inventaire_scan';

    protected $fillable = [
        'tenant_id',
        'numero',
        // Code-barres lu, quand le scan a été rapporté au numéro de son produit.
        'code_lu',
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
