<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Prix saisi pour un produit dans une liste de prix (remplace la formule de la liste). */
class ListePrixProduit extends Model
{
    use BelongsToTenant;

    protected $table = 'listes_prix_produits';

    protected $fillable = ['liste_prix_id', 'produit_id', 'prix'];

    protected $casts = ['prix' => 'float'];
}
