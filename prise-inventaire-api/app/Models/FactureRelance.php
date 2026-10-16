<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Relance de paiement d'une facture en retard : par courriel, ou faite autrement (téléphone, courrier). */
class FactureRelance extends Model
{
    use BelongsToTenant;

    public const MODES = ['courriel', 'telephone', 'courrier', 'autre'];

    protected $table = 'facture_relances';

    protected $fillable = [
        'tenant_id', 'facture_id', 'client_id', 'niveau', 'date_relance', 'mode', 'envoi_id', 'automatique', 'notes', 'cree_par',
    ];

    protected $casts = [
        'date_relance' => 'date',
        'niveau' => 'integer',
        'automatique' => 'boolean',
    ];

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'cree_par');
    }
}
