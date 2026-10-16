<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Personne à joindre chez un fournisseur ; un seul contact principal par fournisseur. */
class FournisseurContact extends Model
{
    use BelongsToTenant;

    protected $table = 'fournisseur_contacts';

    protected $fillable = [
        'fournisseur_id', 'nom', 'fonction', 'telephone', 'telephone_secondaire', 'email',
        'principal', 'recoit_commandes', 'notes', 'actif',
    ];

    protected $casts = [
        'principal' => 'boolean',
        'recoit_commandes' => 'boolean',
        'actif' => 'boolean',
    ];
}
