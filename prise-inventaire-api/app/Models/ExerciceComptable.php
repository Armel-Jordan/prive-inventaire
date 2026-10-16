<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Exercice comptable (année financière), découpé en périodes. */
class ExerciceComptable extends Model
{
    use BelongsToTenant;

    public const OUVERT = 'ouvert';

    public const CLOTURE = 'cloture';

    protected $table = 'exercices_comptables';

    protected $fillable = ['libelle', 'date_debut', 'date_fin', 'statut', 'cloture_le', 'cloture_par'];

    protected $casts = [
        'date_debut' => 'date:Y-m-d',
        'date_fin' => 'date:Y-m-d',
        'cloture_le' => 'datetime',
    ];

    public function periodes(): HasMany
    {
        return $this->hasMany(PeriodeComptable::class, 'exercice_id')->orderBy('numero');
    }

    public function cloturePar(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'cloture_par');
    }

    public function estCloture(): bool
    {
        return $this->statut === self::CLOTURE;
    }
}
