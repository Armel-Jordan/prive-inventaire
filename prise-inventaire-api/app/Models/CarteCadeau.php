<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Carte cadeau : une carte au porteur, vendue puis utilisée comme moyen de paiement jusqu'à épuisement de son solde (la
 * somme de ses mouvements). Elle se recharge ; son solde se rembourse, et elle est alors annulée.
 */
class CarteCadeau extends Model
{
    use BelongsToTenant;

    protected $table = 'cartes_cadeaux';

    protected $fillable = [
        'tenant_id', 'numero', 'client_id', 'devise', 'montant_initial', 'solde', 'date_vente', 'expire_le', 'annulee', 'notes', 'cree_par',
    ];

    protected $casts = [
        'montant_initial' => 'decimal:2',
        'solde' => 'decimal:2',
        'date_vente' => 'date',
        'expire_le' => 'date',
        'annulee' => 'boolean',
    ];

    protected $appends = ['etat'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function mouvements(): HasMany
    {
        return $this->hasMany(CarteCadeauMouvement::class)->orderBy('created_at')->orderBy('id');
    }

    /** Annulée, expirée (date dépassée), épuisée (solde nul) ou active. */
    public function getEtatAttribute(): string
    {
        return match (true) {
            $this->annulee => 'annulee',
            $this->expire_le !== null && $this->expire_le->lt(today()) => 'expiree',
            (float) $this->solde <= 0.004 => 'epuisee',
            default => 'active',
        };
    }
}
