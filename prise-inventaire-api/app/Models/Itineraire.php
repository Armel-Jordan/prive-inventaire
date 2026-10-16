<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Itinéraire régulier de livraison ou de visite, éventuellement rattaché à un territoire. */
class Itineraire extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'itineraires';

    protected $fillable = [
        'tenant_id', 'code', 'nom', 'territoire_id', 'description', 'actif',
    ];

    protected $casts = [
        'actif' => 'boolean',
    ];

    public function territoire(): BelongsTo
    {
        return $this->belongsTo(Territoire::class);
    }
}
