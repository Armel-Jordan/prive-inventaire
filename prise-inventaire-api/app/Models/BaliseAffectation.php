<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Item de balise posé sur une fiche ou un document. */
class BaliseAffectation extends Model
{
    use BelongsToTenant;

    protected $table = 'balise_affectations';

    protected $fillable = ['balise_item_id', 'entite', 'entite_id'];

    protected function casts(): array
    {
        return [
            'balise_item_id' => 'integer',
            'entite_id' => 'integer',
        ];
    }
}
