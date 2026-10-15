<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Liste de prix (ex. Grossiste, Détaillant, Export). Le prix d'un produit y est le prix saisi pour ce
 * produit, sinon le prix de base ajusté du pourcentage de la liste, arrondi à sa précision.
 */
class ListePrix extends Model
{
    use BelongsToTenant;

    protected $table = 'listes_prix';

    protected $fillable = ['nom', 'description', 'ajustement_pourcentage', 'precision', 'actif'];

    protected $casts = [
        'ajustement_pourcentage' => 'float',
        'precision' => 'integer',
        'actif' => 'boolean',
    ];

    public function prixProduits(): HasMany
    {
        return $this->hasMany(ListePrixProduit::class);
    }

    /** Prix d'un produit dans la liste, à partir de son prix de base et d'un éventuel prix saisi. */
    public function prixPour(?float $prixBase, ?float $prixSaisi): ?float
    {
        if ($prixSaisi !== null) {
            return round($prixSaisi, $this->precision);
        }

        return $prixBase === null ? null : round($prixBase * (1 + $this->ajustement_pourcentage / 100), $this->precision);
    }
}
