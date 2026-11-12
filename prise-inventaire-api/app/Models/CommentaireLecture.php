<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Dernier commentaire lu par un compte sur une fiche ou un document (socle S4.6). */
class CommentaireLecture extends Model
{
    use BelongsToTenant;

    protected $table = 'commentaire_lectures';

    protected $fillable = ['user_id', 'entite', 'entite_id', 'dernier_lu_id'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'entite_id' => 'integer',
            'dernier_lu_id' => 'integer',
        ];
    }
}
