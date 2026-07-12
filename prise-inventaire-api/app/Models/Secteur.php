<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Secteur extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'secteurs';

    protected $fillable = [
        'tenant_id',
        'code',
        'nom',
        'description',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
        ];
    }
}
