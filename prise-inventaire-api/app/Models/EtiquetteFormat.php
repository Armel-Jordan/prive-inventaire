<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Format d'étiquette : un rouleau (une étiquette par page, de sa taille) ou une planche (une grille sur une page A4 ou
 * Letter), et ce que porte chaque étiquette (voir App\Services\Etiquettes).
 */
class EtiquetteFormat extends Model
{
    use BelongsToTenant;

    protected $table = 'etiquette_formats';

    protected $fillable = [
        'tenant_id', 'nom', 'support', 'largeur_mm', 'hauteur_mm', 'page', 'colonnes', 'rangees', 'marge_haut_mm',
        'marge_gauche_mm', 'espace_colonnes_mm', 'espace_rangees_mm', 'contenu', 'taille_police', 'par_defaut', 'actif', 'ordre',
    ];

    protected $casts = [
        'largeur_mm' => 'float',
        'hauteur_mm' => 'float',
        'colonnes' => 'integer',
        'rangees' => 'integer',
        'marge_haut_mm' => 'float',
        'marge_gauche_mm' => 'float',
        'espace_colonnes_mm' => 'float',
        'espace_rangees_mm' => 'float',
        'contenu' => 'array',
        'taille_police' => 'integer',
        'par_defaut' => 'boolean',
        'actif' => 'boolean',
        'ordre' => 'integer',
    ];

    /** Étiquettes par page : une sur un rouleau, la grille d'une planche. */
    public function parPage(): int
    {
        return $this->support === 'planche' ? $this->colonnes * $this->rangees : 1;
    }
}
