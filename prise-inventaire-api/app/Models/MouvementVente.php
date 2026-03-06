<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MouvementVente extends Model
{
    use HasFactory;

    protected $table = 'mouvements_inventaire';

    protected $fillable = [
        'produit_id', 'type_mouvement', 'quantite',
        'localisation_source_type', 'localisation_source_id',
        'localisation_dest_type', 'localisation_dest_id',
        'reference_type', 'reference_id', 'motif', 'effectue_par',
    ];

    protected $casts = [
        'quantite' => 'integer',
        'localisation_source_id' => 'integer',
        'localisation_dest_id' => 'integer',
        'reference_id' => 'integer',
        'effectue_par' => 'integer',
    ];

    public static function creerMouvement(
        int $produitId,
        string $type,
        int $quantite,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $destType = null,
        ?int $destId = null,
        ?string $refType = null,
        ?int $refId = null,
        ?string $motif = null,
        ?int $effectuePar = null
    ): self {
        return self::create([
            'produit_id' => $produitId,
            'type_mouvement' => $type,
            'quantite' => $quantite,
            'localisation_source_type' => $sourceType,
            'localisation_source_id' => $sourceId,
            'localisation_dest_type' => $destType,
            'localisation_dest_id' => $destId,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'motif' => $motif,
            'effectue_par' => $effectuePar,
        ]);
    }
}
