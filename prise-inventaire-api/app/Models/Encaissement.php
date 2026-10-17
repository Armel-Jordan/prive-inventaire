<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Encaissement d'un client : l'argent reçu en une fois (chèque, virement…), réparti sur plusieurs de ses factures. Chaque
 * ligne crée un paiement de sa facture ; le surplus reste au crédit du client (acompte) ; l'escompte accordé règle la
 * différence en sa faveur. Annulé, ses paiements sont retirés des factures.
 */
class Encaissement extends Model
{
    use BelongsToTenant;

    public const VALIDE = 'valide';

    public const ANNULE = 'annule';

    public const MODES = ['especes', 'cheque', 'virement', 'carte', 'autre'];

    protected $table = 'encaissements';

    protected $fillable = [
        'tenant_id', 'numero', 'client_id', 'date_encaissement', 'mode', 'reference', 'devise', 'taux_change', 'montant',
        'montant_applique', 'escompte', 'surplus', 'acompte_id', 'caisse_session_id', 'compte_bancaire_id', 'notes', 'statut', 'enregistre_par',
        'annule_le', 'annule_par', 'motif_annulation',
    ];

    protected $casts = [
        'date_encaissement' => 'date',
        'taux_change' => 'decimal:6',
        'montant' => 'decimal:2',
        'montant_applique' => 'decimal:2',
        'escompte' => 'decimal:2',
        'surplus' => 'decimal:2',
        'annule_le' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(EncaissementLigne::class)->orderBy('id');
    }

    /** Acompte créé avec le surplus. */
    public function acompte(): BelongsTo
    {
        return $this->belongsTo(Acompte::class);
    }

    /** Compte bancaire par lequel passe l'argent (vide : le compte de banque des réglages de comptabilisation). */
    public function compteBancaire(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class)->withTrashed();
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
