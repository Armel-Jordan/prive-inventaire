<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Note de crédit (avoir) sur une facture émise. Brouillon modifiable, puis émise : numérotée et imputée sur ce qui
 * reste dû de sa facture. Le crédit encore disponible se rembourse au client ou s'impute sur une autre de ses
 * factures ; entièrement utilisé, la note est soldée.
 */
class Avoir extends Model
{
    use BelongsToTenant;

    public const BROUILLON = 'brouillon';

    public const EMIS = 'emis';

    public const SOLDE = 'solde';

    /** Notes émises, soldées ou non. */
    public const EMISES = [self::EMIS, self::SOLDE];

    protected $fillable = [
        'tenant_id', 'numero', 'facture_id', 'client_id', 'date_avoir', 'statut', 'motif', 'notes',
        'montant_ht', 'montant_tva', 'montant_ttc', 'montant_impute', 'montant_rembourse',
        'emis_le', 'emis_par', 'cree_par',
    ];

    protected $casts = [
        'date_avoir' => 'date',
        'emis_le' => 'datetime',
        'montant_ht' => 'decimal:2',
        'montant_tva' => 'decimal:2',
        'montant_ttc' => 'decimal:2',
        'montant_impute' => 'decimal:2',
        'montant_rembourse' => 'decimal:2',
    ];

    protected $appends = ['credit_disponible'];

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(AvoirLigne::class);
    }

    public function imputations(): HasMany
    {
        return $this->hasMany(AvoirImputation::class)->orderBy('date_imputation')->orderBy('id');
    }

    public function remboursements(): HasMany
    {
        return $this->hasMany(AvoirRemboursement::class)->orderBy('date_remboursement')->orderBy('id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'cree_par');
    }

    public function emetteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'emis_par');
    }

    /** Crédit encore disponible : montant de la note moins ses imputations et ses remboursements. */
    public function getCreditDisponibleAttribute(): float
    {
        if (! in_array($this->statut, self::EMISES, true)) {
            return 0.0;
        }

        return max(0.0, round((float) $this->montant_ttc - (float) $this->montant_impute - (float) $this->montant_rembourse, 2));
    }
}
