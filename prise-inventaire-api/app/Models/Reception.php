<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Réception fournisseur : document numéroté qui regroupe les lignes reçues d'un fournisseur en une livraison (une ou
 * plusieurs de ses commandes), avec son bon de livraison et son connaissement. Enregistrée, elle se corrige (date,
 * références, coûts) ; annulée, ses quantités sont retirées des commandes et ne comptent plus nulle part.
 */
class Reception extends Model
{
    use BelongsToTenant;

    public const ENREGISTREE = 'enregistree';

    public const ANNULEE = 'annulee';

    protected $table = 'receptions';

    protected $fillable = [
        'tenant_id', 'numero', 'fournisseur_id', 'date_reception', 'bon_livraison', 'connaissement', 'notes', 'statut',
        'recue_par', 'annulee_le', 'annulee_par', 'motif_annulation', 'facture_fournisseur_id',
        // Statut personnalisé : étiquette de repérage, sans effet sur le cycle (phase 1.11c).
        'statut_personnalise_id',
    ];

    protected $casts = [
        'date_reception' => 'date',
        'annulee_le' => 'datetime',
    ];

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class)->withTrashed();
    }

    /** Statut personnalisé (étiquette de repérage, phase 1.11c ; même retiré du référentiel depuis). */
    public function statutPersonnalise(): BelongsTo
    {
        return $this->belongsTo(StatutPersonnalise::class)->withTrashed();
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(ReceptionArrivagesLigne::class, 'reception_id');
    }

    /** Coûts annexes (transport, douane…) répartis sur les lignes. */
    public function frais(): HasMany
    {
        return $this->hasMany(ReceptionFrais::class)->orderBy('id');
    }

    /** Facture fournisseur qui règle la réception (« facturée »). */
    public function factureFournisseur(): BelongsTo
    {
        return $this->belongsTo(FactureFournisseur::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'recue_par');
    }

    public function auteurAnnulation(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'annulee_par');
    }
}
