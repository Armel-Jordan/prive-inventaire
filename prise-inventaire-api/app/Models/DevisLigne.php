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
        // Taxes de la ligne (DE1), voir App\Services\TaxesDevis : groupe, taux effectif, montants.
        'groupe_taxe_id', 'taux_tva', 'montant_ht', 'montant_tva', 'montant_ttc',
    ];

    protected $casts = [
        'prix_unitaire' => 'decimal:2',
        'montant_ligne' => 'decimal:2',
        'facteur' => 'float',
        'taux_tva' => 'float',
        'montant_ht' => 'decimal:2',
        'montant_tva' => 'decimal:2',
        'montant_ttc' => 'decimal:2',
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
