<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Session de caisse : de l'ouverture (fond initial) à la fermeture (tiroir compté). Les chiffres d'une session fermée
 * sont figés : le sommaire réimprimé plus tard montre toujours les mêmes montants.
 */
class CaisseSession extends Model
{
    use BelongsToTenant;

    public const OUVERTE = 'ouverte';

    public const FERMEE = 'fermee';

    protected $fillable = [
        'tenant_id', 'caisse_id', 'statut', 'ouverte_le', 'ouverte_par', 'fermee_le', 'fermee_par', 'note_fermeture',
        'chiffres', 'total_ecart',
    ];

    protected $casts = [
        'ouverte_le' => 'datetime',
        'fermee_le' => 'datetime',
        'chiffres' => 'array',
        'total_ecart' => 'decimal:2',
    ];

    public function caisse(): BelongsTo
    {
        return $this->belongsTo(Caisse::class)->withTrashed();
    }

    public function mouvements(): HasMany
    {
        return $this->hasMany(CaisseMouvement::class)->orderBy('created_at')->orderBy('id');
    }

    public function ouvreur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'ouverte_par');
    }

    public function fermeur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'fermee_par');
    }

    public function estOuverte(): bool
    {
        return $this->statut === self::OUVERTE;
    }
}
