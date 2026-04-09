<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoriquePrixAchat extends Model
{
    use HasFactory;

    protected $table = 'historique_prix_achat';

    protected $fillable = [
        'produit_id',
        'fournisseur_id',
        'prix_achat',
        'date_effet',
        'com_four_entete_id',
    ];

    protected $casts = [
        'date_effet' => 'date',
        'prix_achat' => 'decimal:2',
    ];

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id');
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class, 'fournisseur_id');
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(ComFourEntete::class, 'com_four_entete_id');
    }
}
