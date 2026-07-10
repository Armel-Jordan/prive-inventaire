<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Isolation multi-tenant automatique pour un modèle Eloquent.
 *
 * - Lecture  : filtrée par TenantScope selon le TenantContext courant.
 * - Écriture : `tenant_id` injecté automatiquement (et écrase toute valeur fournie
 *   par le client — anti-spoofing). Refuse la création sans tenant résolu.
 *
 * Le modèle doit posséder la colonne renvoyée par getTenantColumn() (par défaut
 * `tenant_id`).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);
            $column = $model->getTenantColumn();

            if ($context->isSuperAdmin()) {
                // En contexte super-admin, le tenant_id doit être fourni explicitement.
                if (empty($model->getAttribute($column))) {
                    throw new RuntimeException(
                        'tenant_id explicite requis en contexte super-admin pour '.$model::class
                    );
                }

                return;
            }

            if ($context->hasTenant()) {
                $model->setAttribute($column, $context->getTenantId());

                return;
            }

            throw new RuntimeException(
                'Impossible de créer '.$model::class.' : aucun tenant résolu (TenantContext).'
            );
        });
    }

    public function getTenantColumn(): string
    {
        return 'tenant_id';
    }

    public function getQualifiedTenantColumn(): string
    {
        return $this->getTable().'.'.$this->getTenantColumn();
    }

    /** Requête ignorant volontairement le scope tenant (usage explicite et audité). */
    public static function withoutTenantScope(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }
}
