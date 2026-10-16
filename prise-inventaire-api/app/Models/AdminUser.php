<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class AdminUser extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $table = 'admin_users';

    protected $fillable = [
        'tenant_id',
        'nom',
        'email',
        'password',
        'role',
        // Rôle personnalisé de l'entreprise : ses droits par module remplacent ceux du rôle système.
        'role_custom_id',
        'actif',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        // Double authentification : jamais renvoyé par l'API.
        'deux_facteurs_secret',
        'deux_facteurs_codes_secours',
        'deux_facteurs_dernier_pas',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'derniere_connexion' => 'datetime',
            'password' => 'hashed',
            'deux_facteurs_secret' => 'encrypted',
            'deux_facteurs_codes_secours' => 'encrypted:array',
            'deux_facteurs_active_le' => 'datetime',
            'deux_facteurs_dernier_pas' => 'integer',
        ];
    }

    /** Double authentification active : un code est demandé à chaque connexion. */
    public function deuxFacteursActive(): bool
    {
        return $this->deux_facteurs_active_le !== null && filled($this->deux_facteurs_secret);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'manager']);
    }

    /** Dernier administrateur actif de son entreprise : il ne peut être ni rétrogradé, ni désactivé, ni privé d'accès. */
    public function estDernierAdministrateur(): bool
    {
        return $this->role === 'admin' && $this->actif
            && ! static::where('tenant_id', $this->tenant_id)->where('role', 'admin')->where('actif', true)->whereKeyNot($this->id)->exists();
    }
}
