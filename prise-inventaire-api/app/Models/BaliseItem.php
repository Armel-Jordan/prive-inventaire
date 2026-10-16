<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Valeur possible d'une balise (Nord, Sud…), avec sa couleur de pastille. */
class BaliseItem extends Model
{
    use BelongsToTenant;

    public const COULEURS = ['gris', 'bleu', 'vert', 'jaune', 'orange', 'rouge', 'violet', 'rose'];

    protected $table = 'balise_items';

    protected $fillable = ['balise_id', 'libelle', 'couleur', 'ordre', 'actif'];

    protected function casts(): array
    {
        return [
            'balise_id' => 'integer',
            'ordre' => 'integer',
            'actif' => 'boolean',
        ];
    }

    public function balise(): BelongsTo
    {
        return $this->belongsTo(Balise::class);
    }
}
