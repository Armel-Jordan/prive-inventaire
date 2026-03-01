<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $table = 'tenants';

    protected $fillable = [
        'nom',
        'slug',
        'db_name',
        'db_host',
        'db_port',
        'db_username',
        'db_password',
        'actif',
        'date_expiration',
        'plan',
        'renouvelable',
        'duree_abonnement',
    ];

    protected $hidden = [
        'db_password',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'renouvelable' => 'boolean',
            'date_expiration' => 'date',
            'db_password' => 'encrypted',
        ];
    }

    public function joursRestants(): int
    {
        if (!$this->date_expiration) {
            return 999;
        }
        return max(0, now()->diffInDays($this->date_expiration, false));
    }

    public function estProcheDExpiration(): bool
    {
        return $this->joursRestants() <= 30 && $this->joursRestants() > 0;
    }

    public function users(): HasMany
    {
        return $this->hasMany(AdminUser::class);
    }

    public function adminUsers(): HasMany
    {
        return $this->hasMany(AdminUser::class)->where('role', '!=', 'super_admin');
    }

    public function isExpired(): bool
    {
        if (!$this->date_expiration) {
            return false;
        }
        return $this->date_expiration->isPast();
    }
}
