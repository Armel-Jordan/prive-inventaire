<?php

namespace App\Models;

use App\Models\Concerns\AUneUnite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevisLigne extends Model
{
    use AUneUnite;

    protected $fillable = [
        'devis_id',
        'produit_id',
        'quantite',
        'prix_unitaire',
        'montant_ligne',
        // Unité de la ligne et son facteur (unités de stock dans une unité) ; vides : l'unité de stock.
        'unite', 'facteur',
    ];

    protected $casts = [
        'prix_unitaire' => 'decimal:2',
        'montant_ligne' => 'decimal:2',
        'facteur' => 'float',
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
