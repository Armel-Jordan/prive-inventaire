<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacturePaiement extends Model
{
    use HasFactory;

    protected $table = 'facture_paiements';

    protected $fillable = [
        'facture_id', 'echeance_id', 'acompte_id', 'date_paiement', 'montant',
        'mode_paiement', 'reference', 'notes', 'enregistre_par',
    ];

    protected $casts = [
        'date_paiement' => 'date',
        'montant' => 'decimal:2',
    ];

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function echeance(): BelongsTo
    {
        return $this->belongsTo(FactureEcheance::class, 'echeance_id');
    }

    /** Acompte avec lequel le paiement a été fait (application d'un acompte), sinon null. */
    public function acompte(): BelongsTo
    {
        return $this->belongsTo(Acompte::class);
    }

    protected static function booted(): void
    {
        static::created(function (FacturePaiement $paiement) {
            $paiement->facture->updateMontantPaye();
        });
    }
}
