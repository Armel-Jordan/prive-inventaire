<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Définition d'un champ ajouté par l'entreprise à un type de fiche ou de document. */
class ChampPersonnalise extends Model
{
    use BelongsToTenant;

    protected $table = 'champs_personnalises';

    protected $fillable = [
        'entite',
        'code',
        'libelle',
        'type',
        'options',
        'format',
        'decimales',
        'obligatoire',
        'sans_doublon',
        'valeur_defaut',
        'aide',
        'ordre',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'decimales' => 'integer',
            'obligatoire' => 'boolean',
            'sans_doublon' => 'boolean',
            'ordre' => 'integer',
            'actif' => 'boolean',
        ];
    }

    public function valeurs(): HasMany
    {
        return $this->hasMany(ChampPersonnaliseValeur::class, 'champ_personnalise_id');
    }
}
