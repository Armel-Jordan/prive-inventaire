<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Transaction bancaire sans facture : un dépôt (argent reçu : intérêts, apport, recette diverse), un retrait (argent
 * sorti : frais bancaires, prélèvement, dépense payée par chèque ou par Internet) réparti sur des comptes de contrepartie,
 * ou un virement vers un autre compte bancaire de l'entreprise. Annulée, son écriture est contre-passée.
 */
class TransactionBancaire extends Model
{
    use BelongsToTenant;

    public const DEPOT = 'depot';

    public const RETRAIT = 'retrait';

    public const VIREMENT = 'virement';

    public const TYPES = [self::DEPOT, self::RETRAIT, self::VIREMENT];

    public const VALIDE = 'valide';

    public const ANNULE = 'annule';

    protected $table = 'transactions_bancaires';

    protected $fillable = [
        'tenant_id', 'numero', 'compte_bancaire_id', 'date_transaction', 'type', 'compte_bancaire_destination_id', 'reference',
        'libelle', 'devise', 'taux_change', 'montant', 'notes', 'statut', 'enregistre_par', 'annule_le', 'annule_par', 'motif_annulation',
    ];

    protected $casts = [
        'date_transaction' => 'date',
        'taux_change' => 'decimal:6',
        'montant' => 'decimal:2',
        'annule_le' => 'datetime',
    ];

    public function compteBancaire(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class)->withTrashed();
    }

    /** Compte bancaire qui reçoit un virement. */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class, 'compte_bancaire_destination_id')->withTrashed();
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(TransactionBancaireLigne::class)->orderBy('ordre')->orderBy('id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'enregistre_par');
    }

    public function auteurAnnulation(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'annule_par');
    }
}
