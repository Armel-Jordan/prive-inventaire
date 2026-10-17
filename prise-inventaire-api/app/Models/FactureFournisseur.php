<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Facture d'un fournisseur (compte à payer) : saisie à la main ou depuis ses réceptions, avec ses taxes, son échéance
 * et ses paiements. Son état de paiement se calcule : impayée, payée en partie, payée, en retard (échéance passée avec
 * un reste à payer), ou annulée.
 */
class FactureFournisseur extends Model
{
    use BelongsToTenant;

    public const ENREGISTREE = 'enregistree';

    public const ANNULEE = 'annulee';

    public const ETATS = ['impayee', 'partielle', 'payee', 'en_retard', 'annulee'];

    public const MODES = ['especes', 'cheque', 'virement', 'carte', 'autre'];

    protected $table = 'factures_fournisseurs';

    protected $fillable = [
        'tenant_id', 'numero', 'fournisseur_id', 'numero_fournisseur', 'date_facture', 'date_echeance', 'condition_paiement_id',
        'devise', 'taux_change', 'montant_ht', 'montant_tva', 'montant_ttc', 'montant_paye', 'statut', 'notes', 'cree_par',
        'annulee_le', 'annulee_par', 'motif_annulation',
    ];

    protected $casts = [
        'date_facture' => 'date',
        'date_echeance' => 'date',
        'taux_change' => 'decimal:6',
        'montant_ht' => 'decimal:2',
        'montant_tva' => 'decimal:2',
        'montant_ttc' => 'decimal:2',
        'montant_paye' => 'decimal:2',
        'annulee_le' => 'datetime',
    ];

    protected $appends = ['reste_a_payer', 'etat', 'jours_retard'];

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class)->withTrashed();
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(FactureFournisseurLigne::class)->orderBy('id');
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(FactureFournisseurPaiement::class)->orderBy('date_paiement')->orderBy('id');
    }

    /** Réceptions réglées par cette facture. */
    public function receptions(): HasMany
    {
        return $this->hasMany(Reception::class)->orderBy('id');
    }

    public function condition(): BelongsTo
    {
        return $this->belongsTo(ConditionPaiement::class, 'condition_paiement_id')->withTrashed();
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'cree_par');
    }

    public function auteurAnnulation(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'annulee_par');
    }

    public function getResteAPayerAttribute(): float
    {
        return $this->statut === self::ANNULEE ? 0.0 : max(0.0, round((float) $this->montant_ttc - (float) $this->montant_paye, 2));
    }

    public function getEtatAttribute(): string
    {
        return match (true) {
            $this->statut === self::ANNULEE => 'annulee',
            $this->reste_a_payer <= 0 => 'payee',
            $this->date_echeance !== null && $this->date_echeance->lt(today()) => 'en_retard',
            (float) $this->montant_paye > 0 => 'partielle',
            default => 'impayee',
        };
    }

    public function getJoursRetardAttribute(): int
    {
        return $this->etat === 'en_retard' ? (int) $this->date_echeance->diffInDays(today()) : 0;
    }

    /** Factures dans un état de paiement (le même calcul que l'attribut « etat »), ou à payer (tout reste dû). */
    public function scopeEtat(Builder $requete, string $etat): Builder
    {
        $reste = fn (Builder $q) => $q->where('statut', self::ENREGISTREE)->whereColumn('montant_paye', '<', 'montant_ttc');

        return match ($etat) {
            'annulee' => $requete->where('statut', self::ANNULEE),
            'payee' => $requete->where('statut', self::ENREGISTREE)->whereColumn('montant_paye', '>=', 'montant_ttc'),
            'en_retard' => $reste($requete)->whereDate('date_echeance', '<', today()),
            'partielle' => $reste($requete)->whereDate('date_echeance', '>=', today())->where('montant_paye', '>', 0),
            'impayee' => $reste($requete)->whereDate('date_echeance', '>=', today())->where('montant_paye', '<=', 0),
            default => $reste($requete),
        };
    }
}
