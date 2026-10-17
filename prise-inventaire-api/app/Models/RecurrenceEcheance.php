<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Date planifiée d'une récurrence, et l'écriture créée quand elle est convertie. */
class RecurrenceEcheance extends Model
{
    protected $table = 'recurrence_echeances';

    protected $fillable = ['recurrence_id', 'date_prevue', 'ecriture_id'];

    protected $casts = [
        'date_prevue' => 'date:Y-m-d',
    ];

    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(RecurrenceComptable::class, 'recurrence_id');
    }

    public function ecriture(): BelongsTo
    {
        return $this->belongsTo(EcritureComptable::class, 'ecriture_id');
    }
}
