<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevisLigne extends Model
{
    protected $fillable = [
        'devis_id',
        'produit_id',
        'quantite',
        'prix_unitaire',
        'montant_ligne',
    ];

    protected $casts = [
        'prix_unitaire' => 'decimal:2',
        'montant_ligne' => 'decimal:2',
    ];

    public function devis(): BelongsTo
    {
        return $this->belongsTo(Devis::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id');
    }
}
