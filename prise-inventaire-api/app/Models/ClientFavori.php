<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Client mis en favori par un utilisateur, pour le retrouver vite. */
class ClientFavori extends Model
{
    use BelongsToTenant;

    protected $table = 'client_favoris';

    protected $fillable = ['user_id', 'client_id'];
}
