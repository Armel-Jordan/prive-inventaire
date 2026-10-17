<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Exemplaire d'un produit suivi par numéro de série (phase 2.8) : reçu d'un fournisseur, puis livré, retourné… */
class NumeroSerie extends Model
{
    use BelongsToTenant;

    public const EN_STOCK = 'en_stock';

    public const STATUTS = [self::EN_STOCK, 'livre', 'retourne', 'rebut'];

    protected $table = 'numeros_serie';

    protected $fillable = ['tenant_id', 'produit_id', 'numero', 'statut', 'reception_ligne_id', 'fournisseur_id', 'recu_le', 'notes'];

    protected function casts(): array
    {
        return [
            'recu_le' => 'date',
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

    public function receptionLigne(): BelongsTo
    {
        return $this->belongsTo(ReceptionArrivagesLigne::class, 'reception_ligne_id');
    }
}
