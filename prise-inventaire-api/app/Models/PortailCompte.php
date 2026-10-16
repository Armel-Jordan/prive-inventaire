<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Accès d'un contact d'un client au portail. Il n'a rien de commun avec les comptes des employés : ses
 * sessions (PortailJeton) ne donnent accès qu'aux routes du portail, et seulement aux données de son client.
 */
class PortailCompte extends Model
{
    use BelongsToTenant;

    protected $table = 'portail_comptes';

    protected $fillable = [
        'client_id', 'nom', 'email', 'telephone', 'password', 'actif', 'invitation_jeton', 'invitation_expire_le',
        'derniere_connexion', 'cree_par',
    ];

    protected $hidden = ['password', 'invitation_jeton'];

    protected $casts = [
        'password' => 'hashed',
        'actif' => 'boolean',
        'invitation_expire_le' => 'datetime',
        'derniere_connexion' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function jetons(): HasMany
    {
        return $this->hasMany(PortailJeton::class, 'portail_compte_id');
    }

    /** Le client a choisi son mot de passe (invitation acceptée). */
    public function estActive(): bool
    {
        return $this->password !== null;
    }
}
