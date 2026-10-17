<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Opération d'un relevé bancaire importé : date, libellé, référence, montant (positif : reçu ; négatif : sorti). Elle se
 * relie aux mouvements du compte comptable du compte bancaire, ou s'exclut (avec un motif) si elle n'en a pas.
 */
class ReleveBancaireLigne extends Model
{
    use BelongsToTenant;

    protected $table = 'releve_bancaire_lignes';

    protected $fillable = [
        'tenant_id', 'releve_id', 'compte_bancaire_id', 'date_operation', 'libelle', 'reference', 'montant', 'identifiant', 'exclue', 'motif_exclusion',
    ];

    protected $casts = [
        'date_operation' => 'date',
        'montant' => 'decimal:2',
        'exclue' => 'boolean',
    ];

    public function releve(): BelongsTo
    {
        return $this->belongsTo(ReleveBancaire::class, 'releve_id');
    }

    public function compteBancaire(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class)->withTrashed();
    }

    public function liens(): HasMany
    {
        return $this->hasMany(ReleveBancaireLien::class, 'releve_ligne_id');
    }
}
