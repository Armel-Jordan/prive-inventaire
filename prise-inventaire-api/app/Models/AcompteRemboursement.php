<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcompteRemboursement extends Model
{
    protected $table = 'acompte_remboursements';

    protected $fillable = [
        'acompte_id', 'date_remboursement', 'montant', 'mode', 'reference', 'notes', 'enregistre_par', 'caisse_session_id',
    ];

    protected $casts = [
        'date_remboursement' => 'date',
        'montant' => 'decimal:2',
    ];

    public function acompte(): BelongsTo
    {
        return $this->belongsTo(Acompte::class);
    }
}
