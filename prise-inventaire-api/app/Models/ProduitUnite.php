<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Unité de vente d'un produit (une unité du référentiel) : son facteur (unités de stock dans une unité), son prix s'il
 * n'est pas celui du produit multiplié par le facteur, et si elle est proposée d'office sur les lignes de vente.
 */
class ProduitUnite extends Model
{
    use BelongsToTenant;

    protected $table = 'produit_unites';

    protected $fillable = ['tenant_id', 'produit_id', 'unite_id', 'facteur', 'prix', 'par_defaut'];

    protected $casts = [
        'facteur' => 'float',
        'prix' => 'float',
        'par_defaut' => 'boolean',
    ];

    /** Code et libellé de l'unité dans les réponses. */
    protected $appends = ['code', 'libelle'];

    protected $hidden = ['unite'];

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id');
    }

    /** L'unité du référentiel, même retirée depuis (la ligne la garde). */
    public function unite(): BelongsTo
    {
        return $this->belongsTo(Unite::class, 'unite_id')->withTrashed();
    }

    public function getCodeAttribute(): ?string
    {
        return $this->unite?->code;
    }

    public function getLibelleAttribute(): ?string
    {
        return $this->unite?->libelle;
    }
}
