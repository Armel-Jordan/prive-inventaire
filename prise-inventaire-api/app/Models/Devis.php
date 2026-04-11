<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Devis extends Model
{
    use HasFactory, SoftDeletes;

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

    public function recalculerTotal(): void
    {
        $this->montant_total = $this->lignes()->sum('montant_ligne');
        $this->save();
    }
}
