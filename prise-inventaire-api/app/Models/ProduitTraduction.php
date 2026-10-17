<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Description d'un produit dans une autre langue (l'espace client la montre dans la langue du visiteur). */
class ProduitTraduction extends Model
{
    use BelongsToTenant;

    /** Langues proposées pour les traductions (celles des écrans de l'application). */
    public const LANGUES = ['fr', 'en'];

    protected $table = 'produit_traductions';

    protected $fillable = ['tenant_id', 'produit_id', 'langue', 'description', 'description_longue'];
}
