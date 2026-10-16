<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Combinaison ordonnée de taxes appliquée à un montant (ex. TPS + TVQ, TVA + CAC). Vide = exonéré. */
class GroupeTaxe extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'groupes_taxes';

    protected $fillable = [
        'tenant_id', 'code', 'nom', 'par_defaut', 'actif',
    ];

    protected $casts = [
        'par_defaut' => 'boolean',
        'actif' => 'boolean',
    ];

    public function lignes(): HasMany
    {
        return $this->hasMany(GroupeTaxeLigne::class)->orderBy('ordre');
    }
}
