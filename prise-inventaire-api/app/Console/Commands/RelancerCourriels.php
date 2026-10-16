<?php

namespace App\Console\Commands;

use App\Jobs\EnvoyerCourriel;
use App\Models\CourrielEnvoi;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * File d'envoi des courriels, toutes entreprises confondues : envoie les courriels restés en
 * attente, relance ceux en échec (au plus CourrielEnvoi::TENTATIVES_MAX tentatives, espacées
 * de 15 minutes) et libère ceux dont l'envoi a été interrompu. Planifiée toutes les 5 minutes.
 */
class RelancerCourriels extends Command
{
    protected $signature = 'courriels:relancer';

    protected $description = 'Envoie les courriels en attente et relance ceux en échec.';

    public function handle(TenantContext $contexte): int
    {
        $ids = $contexte->runWithoutTenant(function () {
            // Envoi interrompu (processus arrêté en cours de route) : repasse en échec, donc relançable.
            CourrielEnvoi::query()
                ->where('statut', CourrielEnvoi::EN_COURS)
                ->where('dernier_essai', '<=', now()->subMinutes(15))
                ->update(['statut' => CourrielEnvoi::ECHEC, 'erreur' => 'Envoi interrompu avant la fin.']);

            return CourrielEnvoi::query()
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->where('statut', CourrielEnvoi::EN_ATTENTE)->where('created_at', '<=', now()->subMinutes(5)))
                    ->orWhere(fn ($q) => $q->where('statut', CourrielEnvoi::ECHEC)
                        ->where('tentatives', '<', CourrielEnvoi::TENTATIVES_MAX)
                        ->where('dernier_essai', '<=', now()->subMinutes(15))))
                ->orderBy('id')
                ->limit(100)
                ->pluck('id');
        });

        foreach ($ids as $id) {
            EnvoyerCourriel::dispatchSync($id);
        }

        $this->info($ids->count().' courriel(s) traité(s).');

        return self::SUCCESS;
    }
}
