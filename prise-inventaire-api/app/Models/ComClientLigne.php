<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComClientLigne extends Model
{
    use HasFactory;

    protected $table = 'com_client_ligne';

    protected $fillable = [
        'com_entete_id', 'produit_id', 'quantite', 'prix_unitaire_ht',
        'taux_tva', 'remise_ligne', 'montant_ht', 'montant_ttc',
    ];

    protected $casts = [
        'quantite' => 'integer',
        'prix_unitaire_ht' => 'decimal:2',
        'taux_tva' => 'decimal:2',
        'remise_ligne' => 'decimal:2',
        'montant_ht' => 'decimal:2',
        'montant_ttc' => 'decimal:2',
    ];

    public function commande(): BelongsTo
    {
        return $this->belongsTo(ComClientEntete::class, 'com_entete_id');
    }

    public function calculerMontants(): void
    {
        $prixApresRemise = $this->prix_unitaire_ht * (1 - $this->remise_ligne / 100);
        $this->montant_ht = $prixApresRemise * $this->quantite;
        $this->montant_ttc = $this->montant_ht * (1 + $this->taux_tva / 100);
    }
}
