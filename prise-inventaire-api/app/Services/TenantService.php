<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class TenantService
{
    protected ?Tenant $currentTenant = null;

    public function setTenant(Tenant $tenant): void
    {
        $this->currentTenant = $tenant;
        $this->configureTenantConnection($tenant);
    }

    public function getCurrentTenant(): ?Tenant
    {
        return $this->currentTenant;
    }

    public function findBySlug(string $slug): ?Tenant
    {
        return Tenant::where('slug', $slug)
            ->where('actif', true)
            ->first();
    }

    public function findByDomain(string $domain): ?Tenant
    {
        $slug = explode('.', $domain)[0];

        return $this->findBySlug($slug);
    }

    protected function configureTenantConnection(Tenant $tenant): void
    {
        Config::set('database.connections.tenant', [
            'driver' => 'mysql',
            'host' => $tenant->db_host,
            'port' => $tenant->db_port,
            'database' => $tenant->db_name,
            'username' => $tenant->db_username,
            'password' => $tenant->db_password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);

        DB::purge('tenant');
        DB::reconnect('tenant');
    }

    public function createTenantDatabase(Tenant $tenant): bool
    {
        try {
            DB::statement("CREATE DATABASE IF NOT EXISTS `{$tenant->db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            $this->configureTenantConnection($tenant);

            $this->runTenantMigrations();

            return true;
        } catch (\Exception $e) {
            \Log::error('Erreur création BD tenant: '.$e->getMessage());

            return false;
        }
    }

    protected function runTenantMigrations(): void
    {
        \Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);
    }
}
