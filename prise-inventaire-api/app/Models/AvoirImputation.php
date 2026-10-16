<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Part d'une note de crédit imputée sur une facture du client : elle réduit ce qui reste dû de la facture. */
class AvoirImputation extends Model
{
    protected $table = 'avoir_imputations';

    protected $fillable = ['avoir_id', 'facture_id', 'date_imputation', 'montant', 'enregistre_par'];

    protected $casts = [
        'date_imputation' => 'date',
        'montant' => 'decimal:2',
    ];

    public function avoir(): BelongsTo
    {
        return $this->belongsTo(Avoir::class);
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }
}
