<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Taxe appliquée à une ligne de document, en instantané (nom et taux au moment du calcul). */
class DocumentTaxe extends Model
{
    use BelongsToTenant;

    protected $table = 'document_taxes';

    protected $fillable = [
        'tenant_id', 'document', 'ligne_id', 'tenant_taxe_id', 'nom', 'taux', 'applique_sur', 'base', 'montant',
    ];

    protected $casts = [
        'taux' => 'decimal:4',
        'base' => 'decimal:2',
        'montant' => 'decimal:2',
    ];
}
