<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mouvement d'un lot, en unités de stock (négatif pour une sortie) : réception, annulation de réception… */
class LotMouvement extends Model
{
    use BelongsToTenant;

    public const RECEPTION = 'reception';

    public const ANNULATION_RECEPTION = 'annulation_reception';

    protected $table = 'lot_mouvements';

    protected $fillable = ['tenant_id', 'lot_id', 'type', 'quantite', 'reception_ligne_id', 'date_mouvement', 'cree_par'];

    protected function casts(): array
    {
        return [
            'quantite' => 'float',
            'date_mouvement' => 'date',
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class, 'lot_id');
    }

    public function receptionLigne(): BelongsTo
    {
        return $this->belongsTo(ReceptionArrivagesLigne::class, 'reception_ligne_id');
    }
}
