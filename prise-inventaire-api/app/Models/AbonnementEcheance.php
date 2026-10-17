<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Échéance d'un abonnement client : date de facturation prévue, période couverte, facture une fois facturée. */
class AbonnementEcheance extends Model
{
    use BelongsToTenant;

    protected $table = 'abonnement_echeances';

    protected $fillable = ['tenant_id', 'abonnement_id', 'date_prevue', 'periode_debut', 'periode_fin', 'facture_id'];

    protected $casts = [
        'date_prevue' => 'date',
        'periode_debut' => 'date',
        'periode_fin' => 'date',
    ];

    public function abonnement(): BelongsTo
    {
        return $this->belongsTo(AbonnementClient::class, 'abonnement_id');
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }
}
