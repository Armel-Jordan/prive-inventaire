<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Zone commerciale ou de livraison. */
class Territoire extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'territoires';

    protected $fillable = [
        'tenant_id', 'code', 'nom', 'description', 'actif',
    ];

    protected $casts = [
        'actif' => 'boolean',
    ];
}
