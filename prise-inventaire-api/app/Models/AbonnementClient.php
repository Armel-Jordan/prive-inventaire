<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Abonnement d'un client : un produit facturé à intervalle régulier (voir App\Services\AbonnementsClients). */
class AbonnementClient extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'abonnements_clients';

    protected $fillable = [
        'tenant_id', 'client_id', 'produit_id', 'description', 'quantite', 'prix_fixe', 'prix_unitaire',
        'frequence', 'jour_facturation', 'mois_facturation', 'jour_debut_periode', 'mois_debut_periode', 'periode_precedente',
        'date_debut', 'date_fin', 'brouillon', 'notes', 'cree_par',
        // Facturation à l'usage : quantité relevée sur la période, au-delà de la quantité incluse.
        'mode_quantite', 'quantite_incluse',
    ];

    protected $casts = [
        'quantite' => 'integer',
        'prix_fixe' => 'boolean',
        'prix_unitaire' => 'decimal:2',
        'jour_facturation' => 'integer',
        'mois_facturation' => 'integer',
        'jour_debut_periode' => 'integer',
        'mois_debut_periode' => 'integer',
        'periode_precedente' => 'boolean',
        'date_debut' => 'date',
        'date_fin' => 'date',
        'brouillon' => 'boolean',
        'quantite_incluse' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id')->withTrashed();
    }

    public function echeances(): HasMany
    {
        return $this->hasMany(AbonnementEcheance::class, 'abonnement_id');
    }

    /** Relevés de consommation (abonnement facturé à l'usage). */
    public function releves(): HasMany
    {
        return $this->hasMany(AbonnementReleve::class, 'abonnement_id');
    }

    public function aLUsage(): bool
    {
        return $this->mode_quantite === 'releve';
    }
}
