<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transporteur extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'transporteurs';

    protected $fillable = [
        'tenant_id', 'code', 'nom', 'telephone', 'email', 'url_suivi', 'numero_compte', 'notes', 'actif',
    ];

    protected $casts = [
        'actif' => 'boolean',
    ];
}
