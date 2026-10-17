<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Déboursé à un fournisseur : l'argent versé en une fois (chèque, virement…), réparti sur plusieurs de ses factures.
 * Chaque ligne crée un paiement de sa facture, avec l'escompte obtenu pour un paiement rapide. Annulé, ses paiements
 * sont retirés des factures.
 */
class Debourse extends Model
{
    use BelongsToTenant;

    public const VALIDE = 'valide';

    public const ANNULE = 'annule';

    public const MODES = ['especes', 'cheque', 'virement', 'carte', 'autre'];

    protected $table = 'debourses';

    protected $fillable = [
        'tenant_id', 'numero', 'fournisseur_id', 'date_debourse', 'mode', 'reference', 'devise', 'taux_change', 'montant',
        'montant_applique', 'escompte', 'notes', 'statut', 'enregistre_par', 'annule_le', 'annule_par', 'motif_annulation',
    ];

    protected $casts = [
        'date_debourse' => 'date',
        'taux_change' => 'decimal:6',
        'montant' => 'decimal:2',
        'montant_applique' => 'decimal:2',
        'escompte' => 'decimal:2',
        'annule_le' => 'datetime',
    ];

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class)->withTrashed();
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(DebourseLigne::class)->orderBy('id');
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
