<?php

namespace App\Support;

/**
 * Source de vérité unique du tenant courant pour la requête/processus en cours.
 *
 * Le Global Scope tenant (TenantScope) et le hook `creating` de BelongsToTenant
 * lisent EXCLUSIVEMENT cet objet — jamais auth()->user() ni le slug directement,
 * qui sont des sources instables (NULL en CLI, slug contrôlé par le client).
 *
 * Alimenté par le middleware ResolveTenantContext (HTTP) ou par runAsTenant()/
 * runWithoutTenant() (CLI, jobs, seeders).
 */
class TenantContext
{
    private ?int $tenantId = null;

    private bool $superAdmin = false;

    /** Passe à true dès qu'un point d'entrée a statué sur le contexte. */
    private bool $resolved = false;

    public function setTenantId(int $tenantId): void
    {
        $this->tenantId = $tenantId;
        $this->superAdmin = false;
        $this->resolved = true;
    }

    public function markSuperAdmin(): void
    {
        $this->superAdmin = true;
        $this->tenantId = null;
        $this->resolved = true;
    }

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /** Réinitialise le contexte (usage tests / réutilisation de worker). */
    public function reset(): void
    {
        $this->tenantId = null;
        $this->superAdmin = false;
        $this->resolved = false;
    }

    /**
     * Exécute une closure SANS filtrage tenant (voit tout), pour les traitements
     * système de confiance (jobs globaux, seeders). Restaure l'état précédent.
     */
    public function runWithoutTenant(callable $callback): mixed
    {
        [$prevId, $prevSa, $prevRes] = [$this->tenantId, $this->superAdmin, $this->resolved];
        $this->markSuperAdmin();

        try {
            return $callback();
        } finally {
            [$this->tenantId, $this->superAdmin, $this->resolved] = [$prevId, $prevSa, $prevRes];
        }
    }

    /**
     * Exécute une closure sous un tenant donné (jobs par-tenant, seeders ciblés).
     * Restaure l'état précédent.
     */
    public function runAsTenant(int $tenantId, callable $callback): mixed
    {
        [$prevId, $prevSa, $prevRes] = [$this->tenantId, $this->superAdmin, $this->resolved];
        $this->setTenantId($tenantId);

        try {
            return $callback();
        } finally {
            [$this->tenantId, $this->superAdmin, $this->resolved] = [$prevId, $prevSa, $prevRes];
        }
    }
}
