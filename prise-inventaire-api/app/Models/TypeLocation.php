<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Type de location (référentiel) : modèle d'un contrat de location (durée, rythme et jour des loyers). */
class TypeLocation extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'types_location';

    protected $fillable = ['tenant_id', 'code', 'nom', 'duree_mois', 'frequence', 'jour_facturation', 'a_echoir', 'actif'];

    protected $casts = [
        'duree_mois' => 'integer',
        'jour_facturation' => 'integer',
        'a_echoir' => 'boolean',
        'actif' => 'boolean',
    ];
}
