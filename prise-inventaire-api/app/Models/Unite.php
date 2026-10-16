<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unite extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'unites';

    protected $fillable = [
        'tenant_id', 'code', 'libelle', 'symbole', 'decimales', 'actif',
    ];

    protected $casts = [
        'decimales' => 'integer',
        'actif' => 'boolean',
    ];
}
