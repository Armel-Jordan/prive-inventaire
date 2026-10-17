<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mouvement de consignation d'un client : dépôt (+), retour (−) ou consommation facturée (−). Le solde consigné d'un
 * produit chez un client est la somme de ses mouvements.
 */
class ConsignationMouvement extends Model
{
    use BelongsToTenant;

    public const TYPES = ['depot', 'retour', 'consommation'];

    protected $table = 'consignation_mouvements';

    protected $fillable = [
        'tenant_id', 'client_id', 'produit_id', 'type', 'quantite', 'cout_unitaire', 'date_mouvement', 'consignation_id', 'facture_id',
        'lieu_retour', 'note', 'cree_par',
    ];

    protected $casts = ['quantite' => 'integer', 'cout_unitaire' => 'decimal:4', 'date_mouvement' => 'datetime'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id')->withTrashed();
    }

    public function consignation(): BelongsTo
    {
        return $this->belongsTo(Consignation::class);
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }
}
