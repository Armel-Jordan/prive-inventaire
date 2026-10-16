<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\TachesPlanifiees;
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
        'parametres',
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
            'parametres' => 'array',
            'prochaine_execution' => 'datetime',
            'derniere_execution' => 'datetime',
            'debut_execution_courante' => 'datetime',
        ];
    }

    /** Paramètres de la tâche : ceux réglés par l'entreprise, complétés par les valeurs par défaut du catalogue. */
    public function parametresEffectifs(): array
    {
        return array_merge(TachesPlanifiees::parametresParDefaut($this->code), $this->parametres ?? []);
    }

    /** Paramètre d'une tâche de l'entreprise courante (valeur par défaut si la tâche n'existe pas encore). */
    public static function parametreDe(string $code, string $cle): mixed
    {
        $tache = static::where('code', $code)->first();
        $parametres = $tache ? $tache->parametresEffectifs() : TachesPlanifiees::parametresParDefaut($code);

        return $parametres[$cle] ?? null;
    }
}
