<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Condition de paiement réutilisable (délai, fin de mois, escompte pour paiement anticipé). */
class ConditionPaiement extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'conditions_paiement';

    protected $fillable = [
        'tenant_id', 'code', 'libelle', 'delai_jours', 'fin_de_mois',
        'escompte_pourcentage', 'escompte_jours', 'par_defaut', 'actif',
    ];

    protected $casts = [
        'delai_jours' => 'integer',
        'fin_de_mois' => 'boolean',
        'escompte_pourcentage' => 'decimal:2',
        'escompte_jours' => 'integer',
        'par_defaut' => 'boolean',
        'actif' => 'boolean',
    ];
}
