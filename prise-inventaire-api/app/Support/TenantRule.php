<?php

namespace App\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Règles de validation scopées au tenant courant.
 * `exists:` de Laravel interroge le query builder brut (hors Global Scope Eloquent),
 * donc on ajoute explicitement la contrainte tenant_id pour empêcher de référencer
 * l'entité d'un autre tenant.
 */
class TenantRule
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)
            ->where('tenant_id', app(TenantContext::class)->getTenantId());
    }
}
