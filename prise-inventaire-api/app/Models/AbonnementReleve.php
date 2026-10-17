<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Relevé de consommation d'un abonnement facturé à l'usage : une quantité consommée, à une date. */
class AbonnementReleve extends Model
{
    use BelongsToTenant;

    protected $table = 'abonnement_releves';

    protected $fillable = ['tenant_id', 'abonnement_id', 'date_releve', 'quantite', 'note', 'cree_par'];

    protected $casts = [
        'date_releve' => 'date',
        'quantite' => 'integer',
    ];

    public function abonnement(): BelongsTo
    {
        return $this->belongsTo(AbonnementClient::class, 'abonnement_id');
    }
}
