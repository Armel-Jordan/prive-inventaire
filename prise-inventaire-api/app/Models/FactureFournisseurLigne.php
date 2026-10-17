<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ligne d'une facture fournisseur, rattachée au besoin à la ligne de réception qu'elle facture. */
class FactureFournisseurLigne extends Model
{
    protected $table = 'facture_fournisseur_lignes';

    protected $fillable = [
        'facture_fournisseur_id', 'produit_id', 'description', 'quantite', 'prix_unitaire', 'montant_ht', 'groupe_taxe_id',
        'taux_tva', 'montant_tva', 'montant_ttc', 'reception_ligne_id',
    ];

    protected $casts = [
        'quantite' => 'decimal:3',
        'prix_unitaire' => 'decimal:4',
        'montant_ht' => 'decimal:2',
        'taux_tva' => 'decimal:3',
        'montant_tva' => 'decimal:2',
        'montant_ttc' => 'decimal:2',
    ];

    public function facture(): BelongsTo
    {
        return $this->belongsTo(FactureFournisseur::class, 'facture_fournisseur_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id')->withTrashed();
    }

    public function ligneReception(): BelongsTo
    {
        return $this->belongsTo(ReceptionArrivagesLigne::class, 'reception_ligne_id');
    }
}
