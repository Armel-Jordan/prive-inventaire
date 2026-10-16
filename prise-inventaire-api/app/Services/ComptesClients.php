<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Facture;
use App\Models\FacturePaiement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Comptes clients (plan de parité fripv2, phase 4.4) : analyse chronologique des sommes dues et relevé de
 * compte d'un client. Tout se calcule à une date à partir des factures émises et des paiements datés : une
 * facture payée depuis compte encore comme due à une date antérieure. Rien n'est enregistré.
 */
class ComptesClients
{
    /** Tranches de retard, en jours après l'échéance (ou la date de facture, sans échéance). */
    public const TRANCHES = ['non_echu', 'jours_1_30', 'jours_31_60', 'jours_61_90', 'plus_90'];

    /** Factures qui engagent le client : les brouillons et les factures annulées n'en font pas partie. */
    private const STATUTS = ['emise', 'partiellement_payee', 'payee'];

    /** Sommes dues par client à une date, réparties par ancienneté du retard. */
    public function analyse(string $date): array
    {
        $factures = $this->facturesDues(null, $date);
        $clients = [];
        $totaux = array_fill_keys(['total', ...self::TRANCHES], 0.0);
        foreach ($factures as $facture) {
            $id = $facture['client_id'];
            $clients[$id] ??= ['client_id' => $id, 'code' => $facture['client_code'], 'raison_sociale' => $facture['client'],
                'total' => 0.0, ...array_fill_keys(self::TRANCHES, 0.0), 'nb_factures' => 0, 'retard_max' => 0];
            $clients[$id][$facture['tranche']] = round($clients[$id][$facture['tranche']] + $facture['reste'], 2);
            $clients[$id]['total'] = round($clients[$id]['total'] + $facture['reste'], 2);
            $clients[$id]['nb_factures']++;
            $clients[$id]['retard_max'] = max($clients[$id]['retard_max'], $facture['retard']);
            $totaux[$facture['tranche']] = round($totaux[$facture['tranche']] + $facture['reste'], 2);
            $totaux['total'] = round($totaux['total'] + $facture['reste'], 2);
        }

        return [
            'date' => $date,
            'clients' => collect($clients)->sortByDesc('total')->values()->all(),
            'totaux' => $totaux,
        ];
    }

    /**
     * Relevé de compte d'un client entre deux dates : solde d'ouverture, factures (débit) et paiements (crédit) avec
     * le solde progressif, solde final, et factures encore dues au dernier jour.
     */
    public function releve(Client $client, string $du, string $au): array
    {
        $factures = Facture::where('client_id', $client->id)->whereIn('statut', self::STATUTS)
            ->whereDate('date_facture', '<=', $au)->get(['id', 'numero', 'date_facture', 'date_echeance', 'montant_ttc']);
        $paiements = FacturePaiement::whereIn('facture_id', $factures->pluck('id'))->whereDate('date_paiement', '<=', $au)
            ->get(['facture_id', 'date_paiement', 'montant', 'mode_paiement', 'reference']);
        $numeros = $factures->pluck('numero', 'id');

        $ouverture = round((float) $factures->filter(fn ($f) => $f->date_facture->toDateString() < $du)->sum('montant_ttc')
            - (float) $paiements->filter(fn ($p) => $p->date_paiement->toDateString() < $du)->sum('montant'), 2);

        $mouvements = $factures->filter(fn ($f) => $f->date_facture->toDateString() >= $du)
            ->map(fn ($f) => ['date' => $f->date_facture->toDateString(), 'type' => 'facture', 'piece' => $f->numero,
                'libelle' => 'Facture '.$f->numero, 'debit' => round((float) $f->montant_ttc, 2), 'credit' => 0.0])
            ->concat($paiements->filter(fn ($p) => $p->date_paiement->toDateString() >= $du)
                ->map(fn ($p) => ['date' => $p->date_paiement->toDateString(), 'type' => 'paiement', 'piece' => $numeros[$p->facture_id] ?? null,
                    'libelle' => trim('Paiement '.($p->mode_paiement ?? '').($p->reference ? " {$p->reference}" : '')), 'debit' => 0.0, 'credit' => round((float) $p->montant, 2)]))
            // Le même jour, les factures avant les paiements qui les soldent.
            ->sortBy([['date', 'asc'], ['type', 'asc']])->values();

        $solde = $ouverture;
        $lignes = $mouvements->map(function (array $m) use (&$solde) {
            $solde = round($solde + $m['debit'] - $m['credit'], 2);

            return $m + ['solde' => $solde];
        })->all();

        return [
            'client' => $client->only(['id', 'code', 'raison_sociale']),
            'du' => $du,
            'au' => $au,
            'solde_ouverture' => $ouverture,
            'lignes' => $lignes,
            'total_debit' => round($mouvements->sum('debit'), 2),
            'total_credit' => round($mouvements->sum('credit'), 2),
            'solde_final' => $solde,
            'factures_dues' => $this->facturesDues($client->id, $au)->values()->all(),
        ];
    }

    /**
     * Factures qui restent dues à une date (montant moins paiements datés jusqu'à ce jour), avec leur retard et
     * leur tranche.
     *
     * @return Collection<int, array>
     */
    private function facturesDues(?int $clientId, string $date): Collection
    {
        $factures = Facture::with('client:id,code,raison_sociale')
            ->whereIn('statut', self::STATUTS)
            ->whereDate('date_facture', '<=', $date)
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->orderBy('date_facture')->orderBy('id')
            ->get(['id', 'numero', 'client_id', 'date_facture', 'date_echeance', 'montant_ttc']);
        $payes = FacturePaiement::whereIn('facture_id', $factures->pluck('id'))->whereDate('date_paiement', '<=', $date)
            ->groupBy('facture_id')->selectRaw('facture_id, SUM(montant) AS total')->pluck('total', 'facture_id');
        $jour = Carbon::parse($date)->startOfDay();

        return $factures->map(function (Facture $f) use ($payes, $jour) {
            $reste = round((float) $f->montant_ttc - (float) ($payes[$f->id] ?? 0), 2);
            $echeance = ($f->date_echeance ?? $f->date_facture)->copy()->startOfDay();
            $retard = max(0, (int) $echeance->diffInDays($jour, false));

            return [
                'facture_id' => $f->id,
                'numero' => $f->numero,
                'client_id' => $f->client_id,
                'client_code' => $f->client?->code,
                'client' => $f->client?->raison_sociale,
                'date_facture' => $f->date_facture->toDateString(),
                'date_echeance' => $f->date_echeance?->toDateString(),
                'montant' => round((float) $f->montant_ttc, 2),
                'reste' => $reste,
                'retard' => $retard,
                'tranche' => match (true) {
                    $retard === 0 => 'non_echu',
                    $retard <= 30 => 'jours_1_30',
                    $retard <= 60 => 'jours_31_60',
                    $retard <= 90 => 'jours_61_90',
                    default => 'plus_90',
                },
            ];
        })->filter(fn (array $f) => $f['reste'] > 0.004)->values();
    }
}
