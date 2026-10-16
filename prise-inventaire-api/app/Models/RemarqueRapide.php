<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Texte réutilisable inséré dans les notes des documents. */
class RemarqueRapide extends Model
{
    use BelongsToTenant;

    protected $table = 'remarques_rapides';

    protected $fillable = ['categorie', 'texte'];
}
