<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ligne d'un contrat de location : produit loué, quantités, prix de contrat et loyer. */
class ContratLocationLigne extends Model
{
    use BelongsToTenant;

    protected $table = 'contrat_location_lignes';

    protected $fillable = [
        'tenant_id', 'contrat_id', 'produit_id', 'quantite', 'quantite_exemptee', 'quantite_retournee', 'date_retour',
        'prix_contrat', 'prix_location', 'facture_id',
    ];

    protected $casts = [
        'quantite' => 'integer',
        'quantite_exemptee' => 'integer',
        'quantite_retournee' => 'integer',
        'date_retour' => 'date',
        'prix_contrat' => 'decimal:2',
        'prix_location' => 'decimal:2',
    ];

    public function contrat(): BelongsTo
    {
        return $this->belongsTo(ContratLocation::class, 'contrat_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id')->withTrashed();
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    /** Unités qui paient un loyer : louées, moins les exemptées et les rendues. */
    public function quantiteFacturable(): int
    {
        return max(0, $this->quantite - $this->quantite_exemptee - $this->quantite_retournee);
    }
}
