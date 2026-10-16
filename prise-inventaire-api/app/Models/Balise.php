<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Balise de classement (Région, Type d'industrie…) et ses items. */
class Balise extends Model
{
    use BelongsToTenant;

    public const TYPES = ['choix', 'options'];

    protected $table = 'balises';

    protected $fillable = ['nom', 'type', 'entites', 'ordre', 'actif'];

    protected function casts(): array
    {
        return [
            'entites' => 'array',
            'ordre' => 'integer',
            'actif' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(BaliseItem::class)->orderBy('ordre')->orderBy('id');
    }
}
