<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Contrepartie d'une transaction bancaire : un compte comptable et son montant (négatif : de l'autre côté). */
class TransactionBancaireLigne extends Model
{
    // tenant_id posé à la création, comme pour la fiche parente (anomalie A14).
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'transaction_bancaire_lignes';

    protected $fillable = ['transaction_bancaire_id', 'compte_comptable_id', 'libelle', 'montant', 'ordre'];

    protected $casts = [
        'montant' => 'decimal:2',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(TransactionBancaire::class, 'transaction_bancaire_id');
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(CompteComptable::class, 'compte_comptable_id');
    }
}
