<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Période d'un exercice comptable (le plus souvent un mois). Fermée, elle n'accepte plus d'écriture. */
class PeriodeComptable extends Model
{
    use BelongsToTenant;

    protected $table = 'periodes_comptables';

    protected $fillable = ['exercice_id', 'numero', 'date_debut', 'date_fin', 'fermee', 'fermee_le', 'fermee_par'];

    protected $casts = [
        'date_debut' => 'date:Y-m-d',
        'date_fin' => 'date:Y-m-d',
        'fermee' => 'boolean',
        'fermee_le' => 'datetime',
    ];

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(ExerciceComptable::class, 'exercice_id');
    }

    public function fermeePar(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'fermee_par');
    }
}
