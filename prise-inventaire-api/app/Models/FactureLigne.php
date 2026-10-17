<?php

namespace App\Models;

use App\Models\Concerns\AUneUnite;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FactureLigne extends Model
{
    use AUneUnite, HasFactory;

    protected $table = 'facture_lignes';

    protected $fillable = [
        'facture_id', 'produit_id', 'quantite', 'prix_unitaire_ht',
        'taux_tva', 'groupe_taxe_id', 'remise_ligne', 'montant_ht', 'montant_tva', 'montant_ttc',
        // Texte sous la désignation du produit (la période facturée d'un abonnement, par exemple).
        'description',
        // Unité de la ligne et son facteur (unités de stock dans une unité) ; vides : l'unité de stock.
        'unite', 'facteur',
    ];

    protected $casts = [
        'quantite' => 'integer',
        'prix_unitaire_ht' => 'decimal:2',
        'taux_tva' => 'decimal:2',
        'remise_ligne' => 'decimal:2',
        'montant_ht' => 'decimal:2',
        'montant_tva' => 'decimal:2',
        'montant_ttc' => 'decimal:2',
        'facteur' => 'float',
    ];

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id');
    }
}
