<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Code-barres d'un produit : son type, la quantité du produit comptée par lecture, s'il est le principal. */
class ProduitCodeBarre extends Model
{
    use BelongsToTenant;

    protected $table = 'produit_codes_barres';

    protected $fillable = ['tenant_id', 'produit_id', 'code', 'type', 'quantite', 'principal', 'notes'];

    protected $casts = [
        'quantite' => 'decimal:3',
        'principal' => 'boolean',
    ];

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id');
    }
}
