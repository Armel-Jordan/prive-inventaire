<?php

namespace App\Jobs;

use App\Models\CourrielEnvoi;
use App\Services\Courriels\EnvoiCourriel;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envoie un courriel de la file, dans le contexte de son entreprise. Avec la file « sync »
 * (production actuelle), l'envoi a lieu pendant la requête ; avec une vraie file, en arrière-plan.
 * Les échecs sont enregistrés sur le courriel : le job lui-même n'échoue pas.
 */
class EnvoyerCourriel implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $envoiId) {}

    public function handle(EnvoiCourriel $service, TenantContext $contexte): void
    {
        $envoi = CourrielEnvoi::withoutTenantScope()->find($this->envoiId);
        if (! $envoi) {
            return;
        }

        $contexte->runAsTenant((int) $envoi->tenant_id, fn () => $service->envoyer($envoi));
    }
}
