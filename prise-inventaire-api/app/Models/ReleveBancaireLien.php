<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lien entre une opération du relevé et un mouvement (ligne d'écriture) du compte comptable du compte bancaire. */
class ReleveBancaireLien extends Model
{
    // tenant_id posé à la création, comme pour la fiche parente (anomalie A14).
    use BelongsToTenant;

    protected $table = 'releve_bancaire_liens';

    protected $fillable = ['releve_ligne_id', 'ecriture_ligne_id'];

    public function ligne(): BelongsTo
    {
        return $this->belongsTo(ReleveBancaireLigne::class, 'releve_ligne_id');
    }

    public function ecritureLigne(): BelongsTo
    {
        return $this->belongsTo(EcritureComptableLigne::class, 'ecriture_ligne_id');
    }
}
