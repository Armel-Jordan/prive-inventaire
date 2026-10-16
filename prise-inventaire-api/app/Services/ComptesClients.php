<?php

namespace App\Services;

use App\Models\Avoir;
use App\Models\AvoirImputation;
use App\Models\AvoirRemboursement;
use App\Models\Client;
use App\Models\Facture;
use App\Models\FacturePaiement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Comptes clients (plan de parité fripv2, phase 4.4) : analyse chronologique des sommes dues et relevé de
 * compte d'un client. Tout se calcule à une date à partir des factures émises, des paiements et des notes de
 * crédit datés : une facture payée depuis compte encore comme due à une date antérieure. Rien n'est enregistré.
 *
 * Notes de crédit : leur part imputée sur une facture réduit ce qui reste dû de celle-ci (à la date de
 * l'imputation) ; leur crédit encore disponible (ni imputé ni remboursé) est dû au client et réduit son solde.
 */
class ComptesClients
{
    /** Tranches de retard, en jours après l'échéance (ou la date de facture, sans échéance). */
    public const TRANCHES = ['non_echu', 'jours_1_30', 'jours_31_60', 'jours_61_90', 'plus_90'];

    /** Factures qui engagent le client : les brouillons et les factures annulées n'en font pas partie. */
    private const STATUTS = ['emise', 'partiellement_payee', 'payee', 'creditee'];

    /** Mouvements d'un même jour : la facture, puis ce qui la crédite, la règle ou rembourse le client. */
    private const ORDRE = ['facture' => 0, 'avoir' => 1, 'paiement' => 2, 'remboursement' => 3];

    /**
     * Sommes dues par client à une date, réparties par ancienneté du retard ; crédits encore disponibles des notes
     * de crédit (dus au client) et solde net.
     */
    public function analyse(string $date): array
    {
        $factures = $this->facturesDues(null, $date);
        $clients = [];
        $totaux = array_fill_keys(['total', ...self::TRANCHES, 'credits', 'solde'], 0.0);
        $nouveau = fn (int $id, ?string $code, ?string $nom) => ['client_id' => $id, 'code' => $code, 'raison_sociale' => $nom,
            'total' => 0.0, ...array_fill_keys(self::TRANCHES, 0.0), 'credits' => 0.0, 'solde' => 0.0, 'nb_factures' => 0, 'retard_max' => 0];
        foreach ($factures as $facture) {
            $id = $facture['client_id'];
            $clients[$id] ??= $nouveau($id, $facture['client_code'], $facture['client']);
            $clients[$id][$facture['tranche']] = round($clients[$id][$facture['tranche']] + $facture['reste'], 2);
            $clients[$id]['total'] = round($clients[$id]['total'] + $facture['reste'], 2);
            $clients[$id]['nb_factures']++;
            $clients[$id]['retard_max'] = max($clients[$id]['retard_max'], $facture['retard']);
            $totaux[$facture['tranche']] = round($totaux[$facture['tranche']] + $facture['reste'], 2);
            $totaux['total'] = round($totaux['total'] + $facture['reste'], 2);
        }
        foreach ($this->creditsDisponibles(null, $date) as $credit) {
            $id = $credit['client_id'];
            $clients[$id] ??= $nouveau($id, $credit['client_code'], $credit['client']);
            $clients[$id]['credits'] = round($clients[$id]['credits'] + $credit['disponible'], 2);
            $totaux['credits'] = round($totaux['credits'] + $credit['disponible'], 2);
        }
        foreach ($clients as $id => $client) {
            $clients[$id]['solde'] = round($client['total'] - $client['credits'], 2);
        }
        $totaux['solde'] = round($totaux['total'] - $totaux['credits'], 2);

        return [
            'date' => $date,
            'clients' => collect($clients)->sortByDesc('total')->values()->all(),
            'totaux' => $totaux,
        ];
    }

    /**
     * Relevé de compte d'un client entre deux dates : solde d'ouverture ; factures et remboursements (débit),
     * paiements et notes de crédit (crédit) avec le solde progressif ; solde final ; factures encore dues et crédits
     * encore disponibles au dernier jour.
     */
    public function releve(Client $client, string $du, string $au): array
    {
        $factures = Facture::where('client_id', $client->id)->whereIn('statut', self::STATUTS)
            ->whereDate('date_facture', '<=', $au)->get(['id', 'numero', 'date_facture', 'date_echeance', 'montant_ttc']);
        $paiements = FacturePaiement::whereIn('facture_id', $factures->pluck('id'))->whereDate('date_paiement', '<=', $au)
            ->get(['facture_id', 'date_paiement', 'montant', 'mode_paiement', 'reference']);
        $avoirs = Avoir::with('facture:id,numero')->where('client_id', $client->id)->whereIn('statut', Avoir::EMISES)
            ->whereDate('date_avoir', '<=', $au)->get(['id', 'numero', 'facture_id', 'date_avoir', 'montant_ttc']);
        $remboursements = AvoirRemboursement::whereIn('avoir_id', $avoirs->pluck('id'))->whereDate('date_remboursement', '<=', $au)
            ->get(['avoir_id', 'date_remboursement', 'montant', 'mode', 'reference']);
        $numeros = $factures->pluck('numero', 'id');
        $numerosAvoirs = $avoirs->pluck('numero', 'id');

        $tous = $factures->map(fn ($f) => ['date' => $f->date_facture->toDateString(), 'type' => 'facture', 'piece' => $f->numero,
            'libelle' => 'Facture '.$f->numero, 'debit' => round((float) $f->montant_ttc, 2), 'credit' => 0.0])
            ->concat($avoirs->map(fn ($a) => ['date' => $a->date_avoir->toDateString(), 'type' => 'avoir', 'piece' => $a->numero,
                'libelle' => 'Note de crédit '.$a->numero.($a->facture ? " (facture {$a->facture->numero})" : ''), 'debit' => 0.0, 'credit' => round((float) $a->montant_ttc, 2)]))
            ->concat($paiements->map(fn ($p) => ['date' => $p->date_paiement->toDateString(), 'type' => 'paiement', 'piece' => $numeros[$p->facture_id] ?? null,
                'libelle' => trim('Paiement '.($p->mode_paiement ?? '').($p->reference ? " {$p->reference}" : '')), 'debit' => 0.0, 'credit' => round((float) $p->montant, 2)]))
            ->concat($remboursements->map(fn ($r) => ['date' => $r->date_remboursement->toDateString(), 'type' => 'remboursement', 'piece' => $numerosAvoirs[$r->avoir_id] ?? null,
                'libelle' => trim('Remboursement '.($r->mode ?? '').($r->reference ? " {$r->reference}" : '')), 'debit' => round((float) $r->montant, 2), 'credit' => 0.0]));

        $avant = $tous->filter(fn (array $m) => $m['date'] < $du);
        $ouverture = round((float) $avant->sum('debit') - (float) $avant->sum('credit'), 2);

        $mouvements = $tous->filter(fn (array $m) => $m['date'] >= $du)
            // Le même jour, la facture avant ce qui la crédite ou la solde.
            ->sortBy([fn (array $a, array $b) => [$a['date'], self::ORDRE[$a['type']]] <=> [$b['date'], self::ORDRE[$b['type']]]])->values();

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
            'credits_disponibles' => $this->creditsDisponibles($client->id, $au)->values()->all(),
        ];
    }

    /**
     * Factures qui restent dues à une date (montant moins paiements et crédits imputés jusqu'à ce jour), avec leur
     * retard et leur tranche.
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
        $credites = AvoirImputation::whereIn('facture_id', $factures->pluck('id'))->whereDate('date_imputation', '<=', $date)
            ->groupBy('facture_id')->selectRaw('facture_id, SUM(montant) AS total')->pluck('total', 'facture_id');
        $jour = Carbon::parse($date)->startOfDay();

        return $factures->map(function (Facture $f) use ($payes, $credites, $jour) {
            $reste = round((float) $f->montant_ttc - (float) ($payes[$f->id] ?? 0) - (float) ($credites[$f->id] ?? 0), 2);
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

    /**
     * Notes de crédit dont il reste du crédit à une date (montant moins imputations et remboursements datés jusqu'à
     * ce jour) : sommes dues au client.
     *
     * @return Collection<int, array>
     */
    private function creditsDisponibles(?int $clientId, string $date): Collection
    {
        $avoirs = Avoir::with('client:id,code,raison_sociale')->whereIn('statut', Avoir::EMISES)
            ->whereDate('date_avoir', '<=', $date)
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->orderBy('date_avoir')->orderBy('id')
            ->get(['id', 'numero', 'client_id', 'date_avoir', 'montant_ttc']);
        $imputes = AvoirImputation::whereIn('avoir_id', $avoirs->pluck('id'))->whereDate('date_imputation', '<=', $date)
            ->groupBy('avoir_id')->selectRaw('avoir_id, SUM(montant) AS total')->pluck('total', 'avoir_id');
        $rembourses = AvoirRemboursement::whereIn('avoir_id', $avoirs->pluck('id'))->whereDate('date_remboursement', '<=', $date)
            ->groupBy('avoir_id')->selectRaw('avoir_id, SUM(montant) AS total')->pluck('total', 'avoir_id');

        return $avoirs->map(fn (Avoir $a) => [
            'avoir_id' => $a->id,
            'numero' => $a->numero,
            'client_id' => $a->client_id,
            'client_code' => $a->client?->code,
            'client' => $a->client?->raison_sociale,
            'date_avoir' => $a->date_avoir->toDateString(),
            'montant' => round((float) $a->montant_ttc, 2),
            'disponible' => round((float) $a->montant_ttc - (float) ($imputes[$a->id] ?? 0) - (float) ($rembourses[$a->id] ?? 0), 2),
        ])->filter(fn (array $c) => $c['disponible'] > 0.004)->values();
    }
}
