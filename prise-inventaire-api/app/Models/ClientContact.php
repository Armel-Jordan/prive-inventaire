<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Personne à joindre chez un client ; un seul contact principal par client. */
class ClientContact extends Model
{
    use BelongsToTenant;

    protected $table = 'client_contacts';

    protected $fillable = [
        'client_id', 'nom', 'fonction', 'telephone', 'telephone_secondaire', 'email',
        'principal', 'recoit_factures', 'recoit_commandes', 'notes', 'actif',
    ];

    protected $casts = [
        'principal' => 'boolean',
        'recoit_factures' => 'boolean',
        'recoit_commandes' => 'boolean',
        'actif' => 'boolean',
    ];
}
