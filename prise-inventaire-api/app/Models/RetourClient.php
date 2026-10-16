<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Retour de marchandise d'un client, avec ou sans facture d'origine. Brouillon modifiable, puis traité : numéroté,
 * chaque ligne remise en stock dans un secteur (un arrivage) ou mise au rebut. Le retour d'une facture peut ensuite
 * donner sa note de crédit.
 */
class RetourClient extends Model
{
    use BelongsToTenant;

    public const BROUILLON = 'brouillon';

    public const TRAITE = 'traite';

    protected $table = 'retours_clients';

    protected $fillable = [
        'tenant_id', 'numero', 'client_id', 'facture_id', 'date_retour', 'statut', 'notes', 'avoir_id',
        'traite_le', 'traite_par', 'cree_par',
    ];

    protected $casts = [
        'date_retour' => 'date',
        'traite_le' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function avoir(): BelongsTo
    {
        return $this->belongsTo(Avoir::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(RetourClientLigne::class)->orderBy('id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'cree_par');
    }

    /** Qui a traité le retour. */
    public function operateur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'traite_par');
    }
}
