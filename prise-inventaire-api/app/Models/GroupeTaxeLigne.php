<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Taxe d'un groupe. `applique_sur` dit à quoi s'applique son taux :
 *  - base                      : le montant HT ;
 *  - base_et_taxes_precedentes : le HT augmenté des taxes déjà calculées (taxe composée) ;
 *  - taxe_precedente           : le montant de la taxe précédente (taxe sur la taxe, ex. CAC).
 */
class GroupeTaxeLigne extends Model
{
    use BelongsToTenant;

    const MODES = ['base', 'base_et_taxes_precedentes', 'taxe_precedente'];

    protected $table = 'groupe_taxe_lignes';

    protected $fillable = [
        'tenant_id', 'groupe_taxe_id', 'tenant_taxe_id', 'ordre', 'applique_sur',
    ];

    protected $casts = [
        'ordre' => 'integer',
    ];

    public function taxe(): BelongsTo
    {
        return $this->belongsTo(TenantTaxe::class, 'tenant_taxe_id');
    }
}
