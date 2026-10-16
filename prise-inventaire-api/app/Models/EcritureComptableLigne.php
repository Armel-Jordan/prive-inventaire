<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ligne d'une écriture : un compte débité ou crédité. */
class EcritureComptableLigne extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'ecritures_comptables_lignes';

    protected $fillable = ['ecriture_id', 'compte_id', 'libelle', 'debit', 'credit', 'ordre'];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    public function ecriture(): BelongsTo
    {
        return $this->belongsTo(EcritureComptable::class, 'ecriture_id');
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(CompteComptable::class, 'compte_id');
    }
}
