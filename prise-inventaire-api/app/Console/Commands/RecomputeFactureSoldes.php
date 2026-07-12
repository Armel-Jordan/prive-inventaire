<?php

namespace App\Console\Commands;

use App\Models\Facture;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Recalcule montant_paye / reste_a_payer / statut de toutes les factures et
 * l'encours des clients. À lancer UNE fois après le déploiement du fix
 * enregistrerPaiement : les factures déjà payées avant le fix ont des soldes
 * potentiellement faux en base.
 */
class RecomputeFactureSoldes extends Command
{
    protected $signature = 'factures:recompute-soldes';

    protected $description = 'Recalcule les soldes/statuts des factures et l\'encours clients (corrige les données antérieures au fix paiement).';

    public function handle(TenantContext $context): int
    {
        $total = 0;

        Tenant::query()->get()->each(function (Tenant $tenant) use ($context, &$total) {
            $context->runAsTenant($tenant->id, function () use ($tenant, &$total) {
                $count = 0;

                Facture::query()->chunkById(200, function ($factures) use (&$count) {
                    foreach ($factures as $facture) {
                        $facture->updateMontantPaye();
                        $count++;
                    }
                });

                $total += $count;
                $this->info("Tenant {$tenant->id} ({$tenant->slug}) : {$count} facture(s) recalculée(s).");
            });
        });

        $this->info("Terminé. {$total} facture(s) recalculée(s) au total.");

        return self::SUCCESS;
    }
}
