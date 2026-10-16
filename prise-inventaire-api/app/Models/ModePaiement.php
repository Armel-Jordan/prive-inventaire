<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ModePaiement extends Model
{
    use BelongsToTenant, SoftDeletes;

    const TYPES = ['especes', 'cheque', 'virement', 'carte', 'mobile_money', 'prelevement', 'autre'];

    protected $table = 'modes_paiement';

    protected $fillable = [
        'tenant_id', 'code', 'libelle', 'type', 'actif',
    ];

    protected $casts = [
        'actif' => 'boolean',
    ];
}
