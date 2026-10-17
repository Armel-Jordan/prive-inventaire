<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Contrat de location d'un client (voir App\Services\ContratsLocation). */
class ContratLocation extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'contrats_location';

    protected $fillable = [
        'tenant_id', 'numero', 'client_id', 'type_location_id', 'date_debut', 'date_fin', 'frequence', 'jour_facturation', 'a_echoir',
        'prochaine_facturation', 'derniere_facturation', 'date_facturation_contrat', 'actif', 'renouvellement_auto', 'taux_contrat',
        'taux_location', 'contrat_precedent_id', 'renouvele_par_id', 'notes', 'cree_par',
    ];

    protected $casts = [
        'date_debut' => 'date',
        'date_fin' => 'date',
        'jour_facturation' => 'integer',
        'a_echoir' => 'boolean',
        'prochaine_facturation' => 'date',
        'derniere_facturation' => 'date',
        'date_facturation_contrat' => 'date',
        'actif' => 'boolean',
        'renouvellement_auto' => 'boolean',
        'taux_contrat' => 'decimal:2',
        'taux_location' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(TypeLocation::class, 'type_location_id')->withTrashed();
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(ContratLocationLigne::class, 'contrat_id');
    }

    /** Factures du contrat : prix de contrat et loyers. */
    public function factures(): HasMany
    {
        return $this->hasMany(Facture::class, 'contrat_location_id');
    }

    public function renouvelePar(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renouvele_par_id')->withTrashed();
    }

    public function precedent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'contrat_precedent_id')->withTrashed();
    }
}
