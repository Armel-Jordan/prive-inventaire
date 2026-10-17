<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mouvement d'une carte cadeau : vente, recharge, utilisation (paiement d'une facture), annulation d'utilisation, remboursement. */
class CarteCadeauMouvement extends Model
{
    use BelongsToTenant;

    public const TYPES = ['vente', 'recharge', 'utilisation', 'annulation_utilisation', 'remboursement'];

    protected $fillable = [
        'tenant_id', 'carte_cadeau_id', 'type', 'montant', 'solde_apres', 'mode', 'reference', 'caisse_session_id', 'facture_id',
        'facture_paiement_id', 'cree_par',
    ];

    protected $casts = [
        'montant' => 'decimal:2',
        'solde_apres' => 'decimal:2',
    ];

    public function carte(): BelongsTo
    {
        return $this->belongsTo(CarteCadeau::class, 'carte_cadeau_id');
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'cree_par');
    }
}
