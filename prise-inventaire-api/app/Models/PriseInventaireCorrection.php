<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Quantité retenue saisie par un responsable pour une ligne d'une prise en cours (recomptage manuel). */
class PriseInventaireCorrection extends Model
{
    use BelongsToTenant;

    protected $table = 'prises_inventaire_corrections';

    protected $fillable = ['prise_inventaire_id', 'secteur', 'produit_numero', 'quantite_retenue', 'raison', 'auteur_id'];

    protected $casts = ['quantite_retenue' => 'float'];

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'auteur_id');
    }
}
