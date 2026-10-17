<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ligne d'un bon de dépôt en consignation : produit, quantité déposée, coût unitaire au dépôt. */
class ConsignationLigne extends Model
{
    use BelongsToTenant;

    protected $table = 'consignation_lignes';

    protected $fillable = ['tenant_id', 'consignation_id', 'produit_id', 'quantite', 'cout_unitaire'];

    protected $casts = ['quantite' => 'integer', 'cout_unitaire' => 'decimal:4'];

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id')->withTrashed();
    }
}
