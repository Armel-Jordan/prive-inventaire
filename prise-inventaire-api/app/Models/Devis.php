<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Devis extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'numero',
        'client_id',
        'date_devis',
        'date_validite',
        'statut',
        'montant_total',
        'notes',
        'created_by',
        'commande_id',
        'devise',
        'taux_change',
    ];

    protected $casts = [
        'date_devis' => 'date',
        'date_validite' => 'date',
        'montant_total' => 'decimal:2',
    ];

    const STATUT_BROUILLON = 'brouillon';

    const STATUT_ENVOYE = 'envoye';

    const STATUT_ACCEPTE = 'accepte';

    const STATUT_REFUSE = 'refuse';

    const STATUT_EXPIRE = 'expire';

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(DevisLigne::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    /** Commande issue de la conversion du devis (un devis ne se convertit qu'une fois). */
    public function commande(): BelongsTo
    {
        return $this->belongsTo(ComClientEntete::class, 'commande_id');
    }

    /** Devis envoyé dont la date de validité est passée. */
    public function estExpire(): bool
    {
        return $this->date_validite !== null && $this->date_validite->lt(now()->startOfDay());
    }

    public function recalculerTotal(): void
    {
        $this->montant_total = $this->lignes()->sum('montant_ligne');
        $this->save();
    }
}
