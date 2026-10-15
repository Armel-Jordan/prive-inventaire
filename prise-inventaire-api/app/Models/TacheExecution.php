<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une exécution d'une tâche planifiée : mode, durée, résultat et message. */
class TacheExecution extends Model
{
    use BelongsToTenant;

    protected $table = 'taches_executions';

    protected $fillable = ['tache_planifiee_id', 'code', 'mode', 'debut', 'fin', 'duree_ms', 'resultat', 'message', 'declenche_par'];

    protected function casts(): array
    {
        return [
            'debut' => 'datetime',
            'fin' => 'datetime',
            'duree_ms' => 'integer',
        ];
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'declenche_par');
    }
}
