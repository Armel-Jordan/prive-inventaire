<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Fichier de relevé bancaire importé (CSV, OFX ou CAMT) : ses opérations, les doublons écartés, qui l'a importé. */
class ReleveBancaire extends Model
{
    use BelongsToTenant;

    protected $table = 'releves_bancaires';

    protected $fillable = ['tenant_id', 'compte_bancaire_id', 'fichier', 'format', 'nombre_lignes', 'doublons', 'importe_par'];

    public function compteBancaire(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class)->withTrashed();
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(ReleveBancaireLigne::class, 'releve_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'importe_par');
    }
}
