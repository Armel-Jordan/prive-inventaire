<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Session d'un compte du portail. Seule l'empreinte du jeton est gardée ; l'entreprise se déduit du compte
 * (pas de colonne tenant_id : la session est lue avant de connaître l'entreprise).
 */
class PortailJeton extends Model
{
    protected $table = 'portail_jetons';

    protected $fillable = ['portail_compte_id', 'jeton', 'expire_le', 'derniere_utilisation'];

    protected $casts = [
        'expire_le' => 'datetime',
        'derniere_utilisation' => 'datetime',
    ];

    public function compte(): BelongsTo
    {
        return $this->belongsTo(PortailCompte::class, 'portail_compte_id')->withoutGlobalScopes();
    }
}
