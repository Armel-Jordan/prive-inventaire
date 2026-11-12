<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Lien entre un document de vente et sa copie (« Copié de », socle S4.3). */
class DocumentCopie extends Model
{
    use BelongsToTenant;

    protected $table = 'documents_copies';

    protected $fillable = ['source_type', 'source_id', 'cible_type', 'cible_id', 'cree_par'];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'cible_id' => 'integer',
            'cree_par' => 'integer',
        ];
    }
}
