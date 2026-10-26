<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Part d'un déboursé portée sur une facture fournisseur : argent et escompte, avec le paiement créé sur la facture. */
class DebourseLigne extends Model
{
    // tenant_id posé à la création, comme pour la fiche parente (anomalie A14).
    use BelongsToTenant;

    protected $table = 'debourse_lignes';

    protected $fillable = ['debourse_id', 'facture_fournisseur_id', 'montant', 'escompte', 'paiement_id'];

    protected $casts = [
        'montant' => 'decimal:2',
        'escompte' => 'decimal:2',
    ];

    public function debourse(): BelongsTo
    {
        return $this->belongsTo(Debourse::class);
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(FactureFournisseur::class, 'facture_fournisseur_id');
    }
}
