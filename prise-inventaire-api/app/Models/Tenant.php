<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, SoftDeletes;

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
        'modules',
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
            'modules' => 'array',
        ];
    }

    /** Modules optionnels activés par défaut pour un plan donné. */
    public static function defaultModulesForPlan(?string $plan): array
    {
        return array_values(config('modules.plans.'.$plan, []));
    }

    /** Modules optionnels actifs de l'entreprise (hors modules cœur). */
    public function optionalModules(): array
    {
        return array_values(array_intersect(
            (array) ($this->modules ?? []),
            config('modules.optional', [])
        ));
    }

    /** Tous les modules actifs : cœur (toujours) + optionnels souscrits. */
    public function activeModules(): array
    {
        return array_values(array_unique(array_merge(
            config('modules.core', []),
            $this->optionalModules()
        )));
    }

    /** L'entreprise a-t-elle accès à ce module ? (cœur = toujours oui) */
    public function hasModule(string $module): bool
    {
        if (in_array($module, config('modules.core', []), true)) {
            return true;
        }

        return in_array($module, (array) ($this->modules ?? []), true);
    }

    public function joursRestants(): int
    {
        if (! $this->date_expiration) {
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
        return $this->hasMany(AdminUser::class);
    }

    public function isExpired(): bool
    {
        if (! $this->date_expiration) {
            return false;
        }

        return $this->date_expiration->isPast();
    }
}
