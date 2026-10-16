<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Réglage d'une tâche planifiée pour une entreprise (activation, récurrence, échéances). */
class TachePlanifiee extends Model
{
    use BelongsToTenant;

    protected $table = 'taches_planifiees';

    protected $fillable = [
        'code',
        'actif',
        'recurrence',
        'heure',
        'jours_semaine',
        'jour_du_mois',
        'prochaine_execution',
        'derniere_execution',
        'dernier_resultat',
        'statut',
        'debut_execution_courante',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'jours_semaine' => 'array',
            'jour_du_mois' => 'integer',
            'prochaine_execution' => 'datetime',
            'derniere_execution' => 'datetime',
            'debut_execution_courante' => 'datetime',
        ];
    }
}
