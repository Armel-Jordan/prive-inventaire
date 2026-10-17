<?php

namespace App\Services;

use App\Models\Acompte;
use App\Models\AcompteRemboursement;
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
 *
 * Acomptes : reçus, ils sont au crédit du client ; appliqués, ce sont des paiements de la facture (qui en réduisent
 * le reste dû) mais pas un nouveau mouvement du compte ; ce qui en reste (ni appliqué ni remboursé) est dû au client.
 *
 * Devises : tous les montants sont dans la devise de l'entreprise, chacun au taux de son document (une facture en
 * USD, ses paiements, escomptes et notes de crédit au taux de la facture ; un acompte au sien).
 */
class ComptesClients
{
    /** Tranches de retard, en jours après l'échéance (ou la date de facture, sans échéance). */
    public const TRANCHES = ['non_echu', 'jours_1_30', 'jours_31_60', 'jours_61_90', 'plus_90'];

    /** Factures qui engagent le client : les brouillons et les factures annulées n'en font pas partie. */
    private const STATUTS = ['emise', 'partiellement_payee', 'payee', 'creditee'];

    /** Modes de paiement, tels qu'écrits dans les libellés du relevé. */
    private const MODES = ['especes' => 'espèces', 'cheque' => 'chèque', 'virement' => 'virement', 'carte' => 'carte', 'autre' => 'autre', 'carte_cadeau' => 'par carte cadeau'];

    /** Mouvements d'un même jour : la facture, puis ce qui la crédite, la règle ou rembourse le client. */
    private const ORDRE = ['facture' => 0, 'avoir' => 1, 'acompte' => 2, 'paiement' => 3, 'escompte' => 4, 'remboursement' => 5];

    /**
     * Sommes dues par client à une date, réparties par ancienneté du retard ; crédits encore disponibles des notes
     * de crédit et des acomptes (dus au client) et solde net.
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
        foreach ($this->creditsDisponibles(null, $date)->concat($this->acomptesDisponibles(null, $date)) as $credit) {
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
            ->whereDate('date_facture', '<=', $au)->get(['id', 'numero', 'date_facture', 'date_echeance', 'montant_ttc', 'devise', 'taux_change']);
        $taux = $factures->mapWithKeys(fn ($f) => [$f->id => $this->taux($f->taux_change)]);
        // Les paiements faits avec un acompte ne sont pas de nouveaux mouvements : l'acompte est déjà au crédit.
        $paiements = FacturePaiement::whereIn('facture_id', $factures->pluck('id'))->whereNull('acompte_id')->whereDate('date_paiement', '<=', $au)
            ->get(['facture_id', 'date_paiement', 'montant', 'escompte', 'mode_paiement', 'reference', 'carte_cadeau_id']);
        $avoirs = Avoir::with('facture:id,numero')->where('client_id', $client->id)->whereIn('statut', Avoir::EMISES)
            ->whereDate('date_avoir', '<=', $au)->get(['id', 'numero', 'facture_id', 'date_avoir', 'montant_ttc', 'taux_change']);
        $tauxAvoirs = $avoirs->mapWithKeys(fn ($a) => [$a->id => $this->taux($a->taux_change)]);
        $remboursements = AvoirRemboursement::whereIn('avoir_id', $avoirs->pluck('id'))->whereDate('date_remboursement', '<=', $au)
            ->get(['avoir_id', 'date_remboursement', 'montant', 'mode', 'reference']);
        $acomptes = Acompte::where('client_id', $client->id)->whereDate('date_acompte', '<=', $au)
            ->get(['id', 'date_acompte', 'montant', 'mode', 'reference', 'taux_change']);
        $tauxAcomptes = $acomptes->mapWithKeys(fn ($a) => [$a->id => $this->taux($a->taux_change)]);
        $remboursementsAcomptes = AcompteRemboursement::whereIn('acompte_id', $acomptes->pluck('id'))->whereDate('date_remboursement', '<=', $au)
            ->get(['acompte_id', 'date_remboursement', 'montant', 'mode', 'reference']);
        $numeros = $factures->pluck('numero', 'id');
        $numerosAvoirs = $avoirs->pluck('numero', 'id');

        $tous = $factures->map(fn ($f) => ['date' => $f->date_facture->toDateString(), 'type' => 'facture', 'piece' => $f->numero,
            'libelle' => 'Facture '.$f->numero.$this->enDevise($f->devise, $f->montant_ttc), 'debit' => round((float) $f->montant_ttc * $taux[$f->id], 2), 'credit' => 0.0])
            ->concat($avoirs->map(fn ($a) => ['date' => $a->date_avoir->toDateString(), 'type' => 'avoir', 'piece' => $a->numero,
                'libelle' => 'Note de crédit '.$a->numero.($a->facture ? " (facture {$a->facture->numero})" : ''), 'debit' => 0.0, 'credit' => round((float) $a->montant_ttc * $tauxAvoirs[$a->id], 2)]))
            ->concat($paiements->map(fn ($p) => ['date' => $p->date_paiement->toDateString(), 'type' => 'paiement', 'piece' => $numeros[$p->facture_id] ?? null,
                'libelle' => trim('Paiement '.$this->mode($p->carte_cadeau_id ? 'carte_cadeau' : $p->mode_paiement).($p->reference ? " {$p->reference}" : '')), 'debit' => 0.0, 'credit' => round((float) $p->montant * ($taux[$p->facture_id] ?? 1.0), 2)]))
            ->concat($remboursements->map(fn ($r) => ['date' => $r->date_remboursement->toDateString(), 'type' => 'remboursement', 'piece' => $numerosAvoirs[$r->avoir_id] ?? null,
                'libelle' => trim('Remboursement '.$this->mode($r->mode).($r->reference ? " {$r->reference}" : '')), 'debit' => round((float) $r->montant * ($tauxAvoirs[$r->avoir_id] ?? 1.0), 2), 'credit' => 0.0]))
            // Escompte accordé avec un paiement : au crédit, comme le paiement qui l'accompagne.
            ->concat($paiements->filter(fn ($p) => (float) $p->escompte > 0)->map(fn ($p) => ['date' => $p->date_paiement->toDateString(), 'type' => 'escompte', 'piece' => $numeros[$p->facture_id] ?? null,
                'libelle' => 'Escompte', 'debit' => 0.0, 'credit' => round((float) $p->escompte * ($taux[$p->facture_id] ?? 1.0), 2)])->values())
            ->concat($acomptes->map(fn ($a) => ['date' => $a->date_acompte->toDateString(), 'type' => 'acompte', 'piece' => $a->reference,
                'libelle' => trim('Acompte '.$this->mode($a->mode)), 'debit' => 0.0, 'credit' => round((float) $a->montant * $tauxAcomptes[$a->id], 2)]))
            ->concat($remboursementsAcomptes->map(fn ($r) => ['date' => $r->date_remboursement->toDateString(), 'type' => 'remboursement', 'piece' => $r->reference,
                'libelle' => trim("Remboursement d'acompte ".$this->mode($r->mode)), 'debit' => round((float) $r->montant * ($tauxAcomptes[$r->acompte_id] ?? 1.0), 2), 'credit' => 0.0]));

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
            'acomptes_disponibles' => $this->acomptesDisponibles($client->id, $au)->values()->all(),
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
            ->get(['id', 'numero', 'client_id', 'date_facture', 'date_echeance', 'montant_ttc', 'devise', 'taux_change']);
        // Paiements et escomptes accordés avec eux.
        $payes = FacturePaiement::whereIn('facture_id', $factures->pluck('id'))->whereDate('date_paiement', '<=', $date)
            ->groupBy('facture_id')->selectRaw('facture_id, SUM(montant + escompte) AS total')->pluck('total', 'facture_id');
        $credites = AvoirImputation::whereIn('facture_id', $factures->pluck('id'))->whereDate('date_imputation', '<=', $date)
            ->groupBy('facture_id')->selectRaw('facture_id, SUM(montant) AS total')->pluck('total', 'facture_id');
        $jour = Carbon::parse($date)->startOfDay();

        return $factures->map(function (Facture $f) use ($payes, $credites, $jour) {
            // Reste dans la devise de la facture, puis dans celle de l'entreprise (au taux de la facture).
            $resteDevise = round((float) $f->montant_ttc - (float) ($payes[$f->id] ?? 0) - (float) ($credites[$f->id] ?? 0), 2);
            $taux = $this->taux($f->taux_change);
            $reste = round($resteDevise * $taux, 2);
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
                'montant' => round((float) $f->montant_ttc * $taux, 2),
                'reste' => $reste,
                // Devise de la facture (null : celle de l'entreprise) et reste dans cette devise.
                'devise' => $f->devise,
                'reste_devise' => $resteDevise,
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
            ->get(['id', 'numero', 'client_id', 'date_avoir', 'montant_ttc', 'taux_change']);
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
            'montant' => round((float) $a->montant_ttc * $this->taux($a->taux_change), 2),
            'disponible' => round(((float) $a->montant_ttc - (float) ($imputes[$a->id] ?? 0) - (float) ($rembourses[$a->id] ?? 0)) * $this->taux($a->taux_change), 2),
        ])->filter(fn (array $c) => $c['disponible'] > 0.004)->values();
    }

    private function mode(?string $mode): string
    {
        return self::MODES[$mode] ?? (string) $mode;
    }

    /** Taux d'un document (1 sans devise étrangère). */
    private function taux(mixed $taux): float
    {
        return (float) $taux > 0 ? (float) $taux : 1.0;
    }

    /** Montant d'origine d'un document en devise étrangère, ajouté au libellé (« (1 200,00 USD) »). */
    private function enDevise(?string $devise, mixed $montant): string
    {
        return $devise ? ' ('.number_format((float) $montant, 2, ',', ' ')." {$devise})" : '';
    }

    /**
     * Acomptes dont il reste quelque chose à une date (montant moins applications et remboursements datés jusqu'à
     * ce jour) : sommes dues au client.
     *
     * @return Collection<int, array>
     */
    private function acomptesDisponibles(?int $clientId, string $date): Collection
    {
        $acomptes = Acompte::with('client:id,code,raison_sociale')->whereDate('date_acompte', '<=', $date)
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->orderBy('date_acompte')->orderBy('id')
            ->get(['id', 'client_id', 'date_acompte', 'mode', 'reference', 'montant', 'taux_change']);
        $appliques = FacturePaiement::whereIn('acompte_id', $acomptes->pluck('id'))->whereDate('date_paiement', '<=', $date)
            ->groupBy('acompte_id')->selectRaw('acompte_id, SUM(montant) AS total')->pluck('total', 'acompte_id');
        $rembourses = AcompteRemboursement::whereIn('acompte_id', $acomptes->pluck('id'))->whereDate('date_remboursement', '<=', $date)
            ->groupBy('acompte_id')->selectRaw('acompte_id, SUM(montant) AS total')->pluck('total', 'acompte_id');

        return $acomptes->map(fn (Acompte $a) => [
            'acompte_id' => $a->id,
            'client_id' => $a->client_id,
            'client_code' => $a->client?->code,
            'client' => $a->client?->raison_sociale,
            'date_acompte' => $a->date_acompte->toDateString(),
            'mode' => $a->mode,
            'reference' => $a->reference,
            'montant' => round((float) $a->montant * $this->taux($a->taux_change), 2),
            'disponible' => round(((float) $a->montant - (float) ($appliques[$a->id] ?? 0) - (float) ($rembourses[$a->id] ?? 0)) * $this->taux($a->taux_change), 2),
        ])->filter(fn (array $c) => $c['disponible'] > 0.004)->values();
    }
}
