<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 1 unité source = facteur × unité cible. */
class UniteConversion extends Model
{
    use BelongsToTenant;

    protected $table = 'unite_conversions';

    protected $fillable = [
        'tenant_id', 'unite_source_id', 'unite_cible_id', 'facteur',
    ];

    protected $casts = [
        'facteur' => 'decimal:6',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Unite::class, 'unite_source_id');
    }

    public function cible(): BelongsTo
    {
        return $this->belongsTo(Unite::class, 'unite_cible_id');
    }
}
