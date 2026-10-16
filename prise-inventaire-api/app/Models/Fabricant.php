<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fabricant extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'fabricants';

    protected $fillable = [
        'tenant_id', 'code', 'nom', 'site_web', 'telephone', 'email', 'notes', 'actif',
    ];

    protected $casts = [
        'actif' => 'boolean',
    ];
}
