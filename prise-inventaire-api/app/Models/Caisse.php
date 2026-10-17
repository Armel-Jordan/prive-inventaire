<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Caisse (référentiel) : un tiroir-caisse ou point d'encaissement, auquel se rattachent les sessions de caisse. */
class Caisse extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['tenant_id', 'code', 'nom', 'actif'];

    protected $casts = [
        'actif' => 'boolean',
    ];

    public function sessions(): HasMany
    {
        return $this->hasMany(CaisseSession::class);
    }

    /** Session en cours (une seule à la fois). */
    public function sessionOuverte(): HasOne
    {
        return $this->hasOne(CaisseSession::class)->where('statut', CaisseSession::OUVERTE);
    }
}
