<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Représentant commercial, avec son taux de commission et son territoire. */
class Representant extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'representants';

    protected $fillable = [
        'tenant_id', 'code', 'nom', 'email', 'telephone', 'taux_commission', 'territoire_id', 'actif',
    ];

    protected $casts = [
        'taux_commission' => 'decimal:2',
        'actif' => 'boolean',
    ];

    public function territoire(): BelongsTo
    {
        return $this->belongsTo(Territoire::class);
    }
}
