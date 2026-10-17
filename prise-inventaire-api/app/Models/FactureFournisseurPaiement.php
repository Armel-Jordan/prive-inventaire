<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Paiement versé à un fournisseur sur une de ses factures. */
class FactureFournisseurPaiement extends Model
{
    use BelongsToTenant;

    protected $table = 'facture_fournisseur_paiements';

    protected $fillable = [
        'tenant_id', 'facture_fournisseur_id', 'date_paiement', 'montant', 'mode', 'reference', 'notes', 'enregistre_par',
        // Escompte obtenu avec le paiement ; déboursé réparti sur plusieurs factures dont le paiement est une part.
        'escompte', 'debourse_id',
    ];

    protected $casts = [
        'date_paiement' => 'date',
        'montant' => 'decimal:2',
        'escompte' => 'decimal:2',
    ];

    public function facture(): BelongsTo
    {
        return $this->belongsTo(FactureFournisseur::class, 'facture_fournisseur_id');
    }

    /** Déboursé dont le paiement est une part, sinon null. */
    public function debourse(): BelongsTo
    {
        return $this->belongsTo(Debourse::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'enregistre_par');
    }
}
