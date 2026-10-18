<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nouveau prix d'une révision : le prix de base d'un produit (liste vide) ou son prix saisi dans une liste. */
class RevisionPrixLigne extends Model
{
    use BelongsToTenant;

    protected $table = 'revision_prix_lignes';

    protected $fillable = ['revision_prix_id', 'produit_id', 'liste_prix_id', 'nouveau_prix', 'prix_remplace'];

    protected $casts = [
        'produit_id' => 'integer',
        'liste_prix_id' => 'integer',
        'nouveau_prix' => 'float',
        'prix_remplace' => 'float',
    ];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(RevisionPrix::class, 'revision_prix_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id')->withTrashed();
    }

    public function listePrix(): BelongsTo
    {
        return $this->belongsTo(ListePrix::class);
    }
}
