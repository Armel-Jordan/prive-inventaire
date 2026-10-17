<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lot d'un produit suivi par lot (phase 2.8) : son numéro, sa date de péremption, le fournisseur de sa première
 * réception. Ses quantités (en unités de stock) sont dans son journal de mouvements ; le solde est ce qui en reste.
 */
class Lot extends Model
{
    use BelongsToTenant;

    protected $table = 'lots';

    protected $fillable = ['tenant_id', 'produit_id', 'numero', 'date_peremption', 'fournisseur_id', 'notes'];

    protected function casts(): array
    {
        return [
            'date_peremption' => 'date',
        ];
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id')->withTrashed();
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class, 'fournisseur_id')->withTrashed();
    }

    public function mouvements(): HasMany
    {
        return $this->hasMany(LotMouvement::class, 'lot_id')->orderBy('date_mouvement')->orderBy('id');
    }

    /** Le même journal sans ordre, pour les sommes (reçu, solde). */
    public function journal(): HasMany
    {
        return $this->hasMany(LotMouvement::class, 'lot_id');
    }
}
