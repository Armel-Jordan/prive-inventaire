<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Produit retourné : quantité, raison, et ce qu'on en fait (remise en stock dans un secteur, ou rebut). */
class RetourClientLigne extends Model
{
    public const REMISE_EN_STOCK = 'remise_en_stock';

    public const REBUT = 'rebut';

    public const TRAITEMENTS = [self::REMISE_EN_STOCK, self::REBUT];

    protected $table = 'retour_client_lignes';

    protected $fillable = [
        'retour_client_id', 'facture_ligne_id', 'produit_id', 'quantite', 'raison_id', 'traitement', 'secteur_id',
        'mouvement_id', 'notes',
    ];

    protected $casts = [
        'quantite' => 'integer',
    ];

    public function retour(): BelongsTo
    {
        return $this->belongsTo(RetourClient::class, 'retour_client_id');
    }

    public function factureLigne(): BelongsTo
    {
        return $this->belongsTo(FactureLigne::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id');
    }

    public function raison(): BelongsTo
    {
        return $this->belongsTo(Raison::class)->withTrashed();
    }

    public function secteur(): BelongsTo
    {
        return $this->belongsTo(Secteur::class)->withTrashed();
    }
}
