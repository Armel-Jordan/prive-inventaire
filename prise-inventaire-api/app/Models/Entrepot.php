<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Lieu de stockage : entrepôt, magasin, véhicule, dépôt… */
class Entrepot extends Model
{
    use BelongsToTenant, SoftDeletes;

    const TYPES = ['entrepot', 'magasin', 'vehicule', 'depot', 'autre'];

    protected $table = 'entrepots';

    protected $fillable = [
        'tenant_id', 'code', 'nom', 'type', 'adresse', 'ville', 'region', 'code_postal',
        'pays', 'telephone', 'responsable', 'par_defaut', 'actif',
    ];

    protected $casts = [
        'par_defaut' => 'boolean',
        'actif' => 'boolean',
    ];
}
