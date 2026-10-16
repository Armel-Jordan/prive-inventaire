<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Historique des taux d'une devise. */
class DeviseTaux extends Model
{
    use BelongsToTenant;

    protected $table = 'devise_taux';

    protected $fillable = [
        'tenant_id', 'devise_id', 'taux', 'date_effet', 'created_by',
    ];

    protected $casts = [
        'taux' => 'decimal:8',
        // Date seule : sans heure ni fuseau, elle ne recule pas d'un jour à l'affichage.
        'date_effet' => 'date:Y-m-d',
    ];

    public function devise(): BelongsTo
    {
        return $this->belongsTo(Devise::class);
    }
}
