<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Acompte : paiement reçu d'un client avant sa facture. Libre, ou réservé à une de ses commandes ; il s'applique au
 * paiement de ses factures (paiements rattachés à l'acompte) et ce qui en reste se rembourse.
 */
class Acompte extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'client_id', 'date_acompte', 'mode', 'reference', 'montant', 'commande_id', 'demande_id',
        'notes', 'montant_applique', 'montant_rembourse', 'enregistre_par',
    ];

    protected $casts = [
        'date_acompte' => 'date',
        'montant' => 'decimal:2',
        'montant_applique' => 'decimal:2',
        'montant_rembourse' => 'decimal:2',
    ];

    protected $appends = ['disponible', 'etat'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(ComClientEntete::class, 'commande_id');
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(DemandeAcompte::class, 'demande_id');
    }

    /** Paiements de factures faits avec l'acompte. */
    public function applications(): HasMany
    {
        return $this->hasMany(FacturePaiement::class)->orderBy('date_paiement')->orderBy('id');
    }

    public function remboursements(): HasMany
    {
        return $this->hasMany(AcompteRemboursement::class)->orderBy('date_remboursement')->orderBy('id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'enregistre_par');
    }

    /** Ce qui reste de l'acompte : ni appliqué sur une facture, ni remboursé. */
    public function getDisponibleAttribute(): float
    {
        return max(0.0, round((float) $this->montant - (float) $this->montant_applique - (float) $this->montant_rembourse, 2));
    }

    /** disponible (intact), entame (en partie utilisé), utilise (plus rien de disponible). */
    public function getEtatAttribute(): string
    {
        return match (true) {
            $this->disponible <= 0 => 'utilise',
            (float) $this->montant_applique > 0 || (float) $this->montant_rembourse > 0 => 'entame',
            default => 'disponible',
        };
    }
}
