<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Catégorie de clients définie par l'entreprise (ex. Grossiste, Détaillant, Administration). */
class ClientCategorie extends Model
{
    use BelongsToTenant;

    protected $table = 'client_categories';

    protected $fillable = ['nom', 'description', 'actif'];

    protected $casts = ['actif' => 'boolean'];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'categorie_id');
    }
}
