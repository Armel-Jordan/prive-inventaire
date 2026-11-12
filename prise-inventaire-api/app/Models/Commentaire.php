<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Commentaire signé et daté sur une fiche ou un document (socle S4.6). */
class Commentaire extends Model
{
    use BelongsToTenant;

    protected $table = 'commentaires';

    protected $fillable = ['entite', 'entite_id', 'auteur_id', 'texte'];

    protected function casts(): array
    {
        return [
            'entite_id' => 'integer',
            'auteur_id' => 'integer',
        ];
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'auteur_id');
    }
}
