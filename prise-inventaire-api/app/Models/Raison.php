<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Raison normalisée d'une opération (mouvement de stock, retour, refus…), par type. */
class Raison extends Model
{
    use BelongsToTenant, SoftDeletes;

    const TYPES = ['mouvement', 'ajustement', 'retour_client', 'retour_fournisseur', 'annulation', 'refus', 'autre'];

    protected $table = 'raisons';

    protected $fillable = [
        'tenant_id', 'type', 'code', 'libelle', 'actif',
    ];

    protected $casts = [
        'actif' => 'boolean',
    ];
}
