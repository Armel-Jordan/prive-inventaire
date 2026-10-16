<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvoirRemboursement extends Model
{
    protected $table = 'avoir_remboursements';

    protected $fillable = [
        'avoir_id', 'date_remboursement', 'montant', 'mode', 'reference', 'notes', 'enregistre_par',
    ];

    protected $casts = [
        'date_remboursement' => 'date',
        'montant' => 'decimal:2',
    ];

    public function avoir(): BelongsTo
    {
        return $this->belongsTo(Avoir::class);
    }
}
