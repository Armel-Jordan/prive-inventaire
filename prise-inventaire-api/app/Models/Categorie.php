<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Catégorie de produits, hiérarchique (parent_id). */
class Categorie extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'categories';

    protected $fillable = [
        'tenant_id', 'code', 'nom', 'parent_id', 'description', 'actif',
    ];

    protected $casts = [
        'actif' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Categorie::class, 'parent_id');
    }

    public function enfants(): HasMany
    {
        return $this->hasMany(Categorie::class, 'parent_id');
    }
}
