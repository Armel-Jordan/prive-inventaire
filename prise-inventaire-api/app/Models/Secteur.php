<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        // Entrepôt du secteur (multi-entrepôts) ; vide : le secteur ne dépend d'aucun entrepôt.
        'entrepot_id',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
        ];
    }

    /** Entrepôt du secteur (gardé s'il a été retiré du référentiel depuis). */
    public function entrepot(): BelongsTo
    {
        return $this->belongsTo(Entrepot::class, 'entrepot_id')->withTrashed();
    }
}
