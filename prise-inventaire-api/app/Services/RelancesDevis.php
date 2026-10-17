<?php

namespace App\Services;

use App\Models\CourrielEnvoi;
use App\Models\Devis;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Relances des devis restés sans réponse (plan de parité fripv2, phase 1.3) : courriel de relance (modèle
 * « relance_devis », devis joint, boutons de réponse en ligne), envoyé à la main ou par la tâche planifiée
 * « relances_devis ». Chaque relance envoyée est comptée sur le devis, avec sa date.
 */
class RelancesDevis
{
    /** Relances automatiques au plus, par devis. */
    public const MAXIMUM_AUTOMATIQUES = 3;

    /** Relance envoyée : comptée sur le devis, avec sa date. */
    public function apresEnvoiCourriel(int $devisId): void
    {
        Devis::whereKey($devisId)->update(['relances' => DB::raw('relances + 1'), 'derniere_relance_le' => now()]);
    }

    /**
     * Devis à relancer automatiquement : envoyés, encore valables, sans réponse depuis au moins N jours (depuis l'envoi
     * ou la dernière relance), relancés moins de MAXIMUM_AUTOMATIQUES fois, au client joignable par courriel, et sans
     * relance déjà en file d'envoi.
     *
     * @return Collection<int, Devis>
     */
    public function aRelancerAutomatiquement(int $jours): Collection
    {
        $limite = now()->subDays(max(0, $jours));
        $enFile = CourrielEnvoi::where('type', 'relance_devis')->whereIn('statut', [CourrielEnvoi::EN_ATTENTE, CourrielEnvoi::EN_COURS])->pluck('document_id');

        return Devis::with('client')
            ->where('statut', Devis::STATUT_ENVOYE)
            ->whereDate('date_validite', '>=', now()->toDateString())
            ->where('relances', '<', self::MAXIMUM_AUTOMATIQUES)
            ->whereNotIn('id', $enFile)
            ->orderBy('id')
            ->get()
            ->filter(fn (Devis $devis) => ($devis->derniere_relance_le ?? $devis->envoye_le ?? $devis->date_devis)->lte($limite))
            ->filter(fn (Devis $devis) => filled($devis->passage_courriel) || filled($devis->client?->email))
            ->values();
    }
}
