<?php

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtre automatiquement toutes les requêtes Eloquent des modèles utilisant
 * le trait BelongsToTenant, selon le TenantContext courant.
 *
 * Comportement :
 *  - Super-admin           -> aucun filtre (voit tout).
 *  - Tenant résolu         -> WHERE tenant_id = <courant>.
 *  - Aucun tenant résolu   -> FAIL-CLOSED : aucune ligne (WHERE 1 = 0).
 *
 * Le fail-closed est volontaire : sur une route non authentifiée/mal câblée, on
 * préfère ne rien renvoyer plutôt que de fuiter toutes les données. Les traitements
 * CLI légitimes qui doivent tout voir passent explicitement par
 * TenantContext::runWithoutTenant().
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isSuperAdmin()) {
            return;
        }

        if ($context->hasTenant()) {
            $builder->where($model->getQualifiedTenantColumn(), $context->getTenantId());

            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
