<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Verrou d'une fiche en cours de modification (phase 6.3) : le compte qui la modifie, jusqu'à quand ; une fiche libérée
 * par un responsable garde qui l'a libérée (le compte qui la modifiait l'apprend au renouvellement suivant).
 */
class Verrou extends Model
{
    use BelongsToTenant;

    protected $table = 'verrous';

    protected $fillable = ['tenant_id', 'entite', 'entite_id', 'admin_user_id', 'expire_le', 'libere_par_id', 'libere_le'];

    protected function casts(): array
    {
        return [
            'entite_id' => 'integer',
            'admin_user_id' => 'integer',
            'expire_le' => 'datetime',
            'libere_par_id' => 'integer',
            'libere_le' => 'datetime',
        ];
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    public function liberePar(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'libere_par_id');
    }

    /** Le verrou tient-il encore (non expiré, non libéré) ? */
    public function actif(): bool
    {
        return $this->libere_le === null && $this->expire_le !== null && $this->expire_le->isFuture();
    }
}
