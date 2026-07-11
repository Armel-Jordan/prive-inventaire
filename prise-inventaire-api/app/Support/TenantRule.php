<?php

namespace App\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Règles de validation scopées au tenant courant.
 * `exists:`/`unique:` de Laravel interrogent le query builder brut (hors Global Scope
 * Eloquent), donc on ajoute explicitement la contrainte tenant_id : empêcher de
 * référencer l'entité d'un autre tenant (exists) et rendre l'unicité par-tenant (unique).
 */
class TenantRule
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)
            ->where('tenant_id', app(TenantContext::class)->getTenantId());
    }

    public static function unique(string $table, string $column, mixed $ignore = null): Unique
    {
        $rule = Rule::unique($table, $column)
            ->where('tenant_id', app(TenantContext::class)->getTenantId());

        return $ignore !== null ? $rule->ignore($ignore) : $rule;
    }
}
