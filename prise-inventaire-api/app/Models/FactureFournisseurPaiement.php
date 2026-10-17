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

    protected $fillable = ['tenant_id', 'facture_fournisseur_id', 'date_paiement', 'montant', 'mode', 'reference', 'notes', 'enregistre_par'];

    protected $casts = [
        'date_paiement' => 'date',
        'montant' => 'decimal:2',
    ];

    public function facture(): BelongsTo
    {
        return $this->belongsTo(FactureFournisseur::class, 'facture_fournisseur_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'enregistre_par');
    }
}
