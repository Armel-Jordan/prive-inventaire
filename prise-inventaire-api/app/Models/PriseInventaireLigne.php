<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Ligne figée d'une prise d'inventaire validée : quantité retenue d'un produit dans un secteur. */
class PriseInventaireLigne extends Model
{
    use BelongsToTenant;

    protected $table = 'prises_inventaire_lignes';

    protected $fillable = [
        'prise_inventaire_id', 'secteur_id', 'secteur', 'produit_numero', 'quantite_comptee', 'nb_scans',
        'quantite_reference', 'reference_prise_id', 'quantite_retenue', 'ecart', 'entrees_depuis', 'sorties_depuis', 'raison',
    ];

    protected $casts = [
        'quantite_comptee' => 'float',
        'nb_scans' => 'integer',
        'quantite_reference' => 'float',
        'quantite_retenue' => 'float',
        'ecart' => 'float',
        'entrees_depuis' => 'float',
        'sorties_depuis' => 'float',
    ];
}
