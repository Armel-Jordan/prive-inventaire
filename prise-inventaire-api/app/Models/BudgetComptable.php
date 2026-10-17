<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Montant budgété d'un compte pour une période d'un exercice, dans le sens naturel du compte. */
class BudgetComptable extends Model
{
    use BelongsToTenant;

    protected $table = 'budgets_comptables';

    protected $fillable = ['tenant_id', 'exercice_id', 'compte_id', 'periode_id', 'montant', 'modifie_par'];

    protected $casts = [
        'montant' => 'decimal:2',
    ];

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(ExerciceComptable::class, 'exercice_id');
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(CompteComptable::class, 'compte_id');
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(PeriodeComptable::class, 'periode_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'modifie_par');
    }
}
