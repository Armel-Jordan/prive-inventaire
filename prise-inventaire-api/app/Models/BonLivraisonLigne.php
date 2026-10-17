<?php

namespace App\Models;

use App\Models\Concerns\AUneUnite;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BonLivraisonLigne extends Model
{
    use AUneUnite, HasFactory;

    protected $table = 'bon_livraison_lignes';

    protected $fillable = [
        'bon_id', 'produit_id', 'quantite_a_livrer',
        'quantite_preparee', 'quantite_livree', 'statut_ligne',
        // Unité de la ligne et son facteur (unités de stock dans une unité) ; vides : l'unité de stock.
        'unite', 'facteur',
    ];

    protected $casts = [
        'quantite_a_livrer' => 'integer',
        'quantite_preparee' => 'integer',
        'quantite_livree' => 'integer',
        'facteur' => 'float',
    ];

    public function bonLivraison(): BelongsTo
    {
        return $this->belongsTo(BonLivraison::class, 'bon_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id');
    }
}
