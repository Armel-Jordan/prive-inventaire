<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rapprochement d'un compte bancaire avec un relevé de la banque (date, solde de fin) : les lignes de son compte
 * comptable pointées une à une. En cours, il se pointe ; terminé (écart nul avec le relevé), ses lignes ne se modifient
 * plus. Le dernier terminé se rouvre.
 */
class RapprochementBancaire extends Model
{
    use BelongsToTenant;

    public const EN_COURS = 'en_cours';

    public const TERMINE = 'termine';

    protected $table = 'rapprochements_bancaires';

    protected $fillable = [
        'tenant_id', 'compte_bancaire_id', 'date_releve', 'solde_releve', 'solde_depart', 'statut', 'notes', 'cree_par', 'termine_le', 'termine_par',
    ];

    protected $casts = [
        'date_releve' => 'date',
        'solde_releve' => 'decimal:2',
        'solde_depart' => 'decimal:2',
        'termine_le' => 'datetime',
    ];

    public function compteBancaire(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class)->withTrashed();
    }

    /** Lignes du compte comptable pointées dans ce rapprochement. */
    public function lignes(): HasMany
    {
        return $this->hasMany(EcritureComptableLigne::class, 'rapprochement_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'cree_par');
    }

    public function auteurFin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'termine_par');
    }
}
