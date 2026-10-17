<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ligne d'un colis : une quantité d'une ligne du bon de livraison. */
class ColisLivraisonLigne extends Model
{
    use BelongsToTenant;

    protected $table = 'bl_colis_lignes';

    protected $fillable = ['tenant_id', 'colis_id', 'bon_livraison_ligne_id', 'quantite'];

    protected $casts = ['quantite' => 'integer'];

    public function colis(): BelongsTo
    {
        return $this->belongsTo(ColisLivraison::class, 'colis_id');
    }

    public function ligne(): BelongsTo
    {
        return $this->belongsTo(BonLivraisonLigne::class, 'bon_livraison_ligne_id');
    }
}
