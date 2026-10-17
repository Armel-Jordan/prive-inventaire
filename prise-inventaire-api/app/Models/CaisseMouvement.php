<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mouvement manuel d'une session de caisse : fond initial, entrée ou sortie d'argent. */
class CaisseMouvement extends Model
{
    use BelongsToTenant;

    public const TYPES = ['fond', 'entree', 'sortie'];

    protected $fillable = ['tenant_id', 'caisse_session_id', 'type', 'mode', 'devise', 'montant', 'note', 'cree_par'];

    protected $casts = [
        'montant' => 'decimal:2',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CaisseSession::class, 'caisse_session_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'cree_par');
    }
}
