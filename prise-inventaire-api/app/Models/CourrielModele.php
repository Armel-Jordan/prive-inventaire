<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Modèle de courriel d'une entreprise pour un type d'envoi et une langue. */
class CourrielModele extends Model
{
    use BelongsToTenant;

    protected $table = 'courriel_modeles';

    protected $fillable = [
        'type',
        'langue',
        'objet',
        'contenu',
        'nom_fichier',
        'repondre_a',
        'accuse_lecture',
    ];

    protected function casts(): array
    {
        return [
            'accuse_lecture' => 'boolean',
        ];
    }
}
