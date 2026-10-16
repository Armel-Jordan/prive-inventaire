<?php

namespace App\Taches;

use App\Models\Facture;
use App\Models\Notification;
use App\Models\TachePlanifiee;
use App\Models\TenantParametres;

/**
 * Signale les factures émises qui restent impayées plus de N jours après leur échéance (après leur date si
 * elles n'ont pas d'échéance). N : paramètre « jours » de la tâche. Une alerte par facture tant qu'elle n'est
 * pas lue.
 */
class SignalerFacturesImpayees implements Tache
{
    public function executer(): string
    {
        $jours = (int) TachePlanifiee::parametreDe('factures_impayees', 'jours');
        $limite = today()->subDays($jours)->toDateString();
        $devise = (string) TenantParametres::query()->value('devise_symbole');

        $factures = Facture::with('client:id,raison_sociale')
            ->whereIn('statut', ['emise', 'partiellement_payee'])
            ->where('reste_a_payer', '>', 0)
            ->where(fn ($q) => $q->whereDate('date_echeance', '<', $limite)
                ->orWhere(fn ($sans) => $sans->whereNull('date_echeance')->whereDate('date_facture', '<', $limite)))
            ->orderBy('date_echeance')
            ->get();

        $signalees = 0;
        foreach ($factures as $facture) {
            $echeance = ($facture->date_echeance ?? $facture->date_facture)?->format('d/m/Y');
            $reste = trim(number_format((float) $facture->reste_a_payer, 2, ',', ' ').' '.$devise);
            $client = $facture->client?->raison_sociale ?? 'client inconnu';
            $alerte = Notification::alerterUneFois(
                'facture_impayee', 'facture_id', 'Facture impayée',
                "La facture {$facture->numero} ({$client}) reste impayée : {$reste} dus depuis le {$echeance}.",
                '/factures', ['facture_id' => $facture->id, 'numero' => $facture->numero],
            );
            $signalees += $alerte ? 1 : 0;
        }

        return $signalees === 0 ? 'Aucune nouvelle facture impayée.' : "{$signalees} facture(s) impayée(s) signalée(s).";
    }
}
