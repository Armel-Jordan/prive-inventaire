<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FactureEcheance extends Model
{
    use HasFactory;

    protected $table = 'facture_echeances';

    protected $fillable = [
        'facture_id', 'date_echeance', 'montant', 'montant_paye', 'statut', 'ordre',
    ];

    protected $casts = [
        'date_echeance' => 'date',
        'montant' => 'decimal:2',
        'montant_paye' => 'decimal:2',
        'ordre' => 'integer',
    ];

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }
}
