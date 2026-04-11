<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantTaxe extends Model
{
    protected $table = 'tenant_taxes';

    protected $fillable = [
        'tenant_id',
        'nom',
        'taux',
        'par_defaut',
    ];

    protected $casts = [
        'taux' => 'decimal:2',
        'par_defaut' => 'boolean',
    ];
}
