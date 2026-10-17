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

    protected $fillable = ['tenant_id', 'abonnement_id', 'date_prevue', 'periode_debut', 'periode_fin', 'facture_id', 'rien_a_facturer'];

    protected $casts = [
        'date_prevue' => 'date',
        'periode_debut' => 'date',
        'periode_fin' => 'date',
        // Abonnement à l'usage : consommation couverte par la quantité incluse, échéance close sans facture.
        'rien_a_facturer' => 'boolean',
    ];

    /** Échéance traitée : facturée, ou close sans rien à facturer. */
    public function scopeTraitee($requete)
    {
        return $requete->where(fn ($q) => $q->whereNotNull('facture_id')->orWhere('rien_a_facturer', true));
    }

    /** Échéance à facturer : ni facturée, ni close. */
    public function scopeAFacturer($requete)
    {
        return $requete->whereNull('facture_id')->where('rien_a_facturer', false);
    }

    public function abonnement(): BelongsTo
    {
        return $this->belongsTo(AbonnementClient::class, 'abonnement_id');
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }
}
