<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Part d'un encaissement portée sur une facture : argent et escompte, avec le paiement créé sur la facture. */
class EncaissementLigne extends Model
{
    protected $table = 'encaissement_lignes';

    protected $fillable = ['encaissement_id', 'facture_id', 'montant', 'escompte', 'facture_paiement_id'];

    protected $casts = [
        'montant' => 'decimal:2',
        'escompte' => 'decimal:2',
    ];

    public function encaissement(): BelongsTo
    {
        return $this->belongsTo(Encaissement::class);
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }
}
