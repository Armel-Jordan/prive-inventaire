<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BonLivraisonLigne extends Model
{
    use HasFactory;

    protected $table = 'bon_livraison_lignes';

    protected $fillable = [
        'bon_id', 'produit_id', 'quantite_a_livrer',
        'quantite_preparee', 'quantite_livree', 'statut_ligne',
    ];

    protected $casts = [
        'quantite_a_livrer' => 'integer',
        'quantite_preparee' => 'integer',
        'quantite_livree' => 'integer',
    ];

    public function bonLivraison(): BelongsTo
    {
        return $this->belongsTo(BonLivraison::class, 'bon_id');
    }
}
