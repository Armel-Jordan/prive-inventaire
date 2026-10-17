<?php

namespace App\Services;

use App\Models\FactureFournisseur;
use App\Models\FactureFournisseurPaiement;
use App\Models\TenantParametres;
use Illuminate\Support\Collection;

/**
 * Paiements dus aux fournisseurs (plan de parité fripv2, phase 4.5 : rapport « Paiements dus — comptes à payer ») : le
 * plan de décaissement. Chaque facture fournisseur encore due, avec son échéance, l'escompte que le fournisseur accorde
 * encore à la date où l'on compte payer, et le net à sortir de la banque.
 *
 * Deux dates, deux rôles (comme fripv2) :
 * - la date du rapport arrête les soldes : les factures et les paiements datés après elle sont ignorés ;
 * - la date « payer le » décide seulement quels escomptes sont encore accordés : le taux de la condition de paiement
 *   sur le TTC, jusqu'à la date de la facture plus les jours d'escompte (le dernier jour compte), si aucun escompte
 *   n'a déjà été pris ; jamais plus que le solde.
 *
 * Les factures se regroupent par fournisseur et par devise ; les devises ne se mélangent jamais dans un sous-total.
 */
class PaiementsDus
{
    /**
     * @param  array{date?: ?string, payer_le?: ?string, echeance_au?: ?string, fournisseur_id?: ?int, devise?: ?string, echus?: bool, escompte_seulement?: bool, inclure_soldees?: bool}  $filtres
     */
    public function rapport(array $filtres): array
    {
        $date = $filtres['date'] ?? today()->toDateString();
        $payerLe = $filtres['payer_le'] ?? $date;
        $echeanceAu = $filtres['echeance_au'] ?? $date;
        $devise = array_key_exists('devise', $filtres) && filled($filtres['devise']) ? $this->devise($filtres['devise']) : false;

        $factures = FactureFournisseur::query()->where('statut', FactureFournisseur::ENREGISTREE)->whereDate('date_facture', '<=', $date)
            ->when($filtres['fournisseur_id'] ?? null, fn ($q, $id) => $q->where('fournisseur_id', $id))
            ->with(['fournisseur:id,code,raison_sociale', 'condition:id,libelle,escompte_pourcentage,escompte_jours'])->get();
        // Réglé à la date du rapport : l'argent versé et les escomptes pris jusque-là.
        $reglements = FactureFournisseurPaiement::query()->whereIn('facture_fournisseur_id', $factures->pluck('id'))->whereDate('date_paiement', '<=', $date)
            ->groupBy('facture_fournisseur_id')->selectRaw('facture_fournisseur_id, SUM(montant + escompte) AS regle, SUM(escompte) AS escomptes')
            ->get()->keyBy('facture_fournisseur_id');

        $lignes = $factures->map(function (FactureFournisseur $f) use ($reglements, $date, $payerLe) {
            $regle = round((float) ($reglements[$f->id]->regle ?? 0), 2);
            $solde = max(0.0, round((float) $f->montant_ttc - $regle, 2));
            $echeance = ($f->date_echeance ?? $f->date_facture)->toDateString();
            $taux = (float) ($f->condition?->escompte_pourcentage ?? 0);
            $limite = $taux > 0 ? $f->date_facture->copy()->addDays((int) $f->condition->escompte_jours)->toDateString() : null;
            $dejaPris = (float) ($reglements[$f->id]->escomptes ?? 0) > 0;
            $escompte = $limite !== null && $payerLe <= $limite && ! $dejaPris && $solde > 0
                ? round(min((float) $f->montant_ttc * $taux / 100, $solde), 2) : 0.0;

            return [
                'id' => $f->id,
                'numero' => $f->numero,
                'numero_fournisseur' => $f->numero_fournisseur,
                'fournisseur_id' => $f->fournisseur_id,
                'fournisseur' => $f->fournisseur?->raison_sociale,
                'code_fournisseur' => $f->fournisseur?->code,
                'devise' => $f->devise,
                'taux_change' => (float) ($f->taux_change ?: 1),
                'date_facture' => $f->date_facture->toDateString(),
                'date_echeance' => $echeance,
                // Retard à la date du rapport ; négatif : l'échéance est encore à venir.
                'jours' => (int) round((strtotime($date) - strtotime($echeance)) / 86400),
                'montant' => (float) $f->montant_ttc,
                'regle' => $regle,
                'solde' => $solde,
                'limite_escompte' => $limite,
                'escompte' => $escompte,
                'net' => max(0.0, round($solde - $escompte, 2)),
            ];
        })->filter(fn (array $l) => ($l['solde'] > 0 || ($filtres['inclure_soldees'] ?? false))
            && $l['date_echeance'] <= $echeanceAu
            && (! ($filtres['echus'] ?? false) || $l['date_echeance'] < $date)
            && (! ($filtres['escompte_seulement'] ?? false) || $l['escompte'] > 0)
            && ($devise === false || DevisesVente::memeDevise($l['devise'], $devise)))
            ->sortBy([['fournisseur', 'asc'], ['devise', 'asc'], ['date_echeance', 'asc'], ['numero', 'asc']])->values();

        $groupes = $lignes->groupBy(fn (array $l) => $l['fournisseur_id'].'|'.($l['devise'] ?? ''))->map(fn (Collection $g) => [
            'fournisseur_id' => $g->first()['fournisseur_id'],
            'fournisseur' => $g->first()['fournisseur'],
            'code_fournisseur' => $g->first()['code_fournisseur'],
            'devise' => $g->first()['devise'],
            'factures' => $g->values()->all(),
            'totaux' => $this->totaux($g),
        ])->values()->all();

        return [
            'date' => $date,
            'payer_le' => $payerLe,
            'echeance_au' => $echeanceAu,
            'groupes' => $groupes,
            // Par devise, jamais mélangées ; puis le total de contrôle dans la devise de l'entreprise.
            'totaux_par_devise' => $lignes->groupBy(fn (array $l) => $l['devise'] ?? '')
                ->map(fn (Collection $g) => ['devise' => $g->first()['devise']] + $this->totaux($g))->values()->all(),
            'total_entreprise' => [
                'solde' => round($lignes->sum(fn (array $l) => $l['solde'] * $l['taux_change']), 2),
                'escompte' => round($lignes->sum(fn (array $l) => $l['escompte'] * $l['taux_change']), 2),
                'net' => round($lignes->sum(fn (array $l) => $l['net'] * $l['taux_change']), 2),
            ],
        ];
    }

    /** @param  Collection<int, array<string, mixed>>  $lignes */
    private function totaux(Collection $lignes): array
    {
        return [
            'nombre' => $lignes->count(),
            'montant' => round($lignes->sum('montant'), 2),
            'regle' => round($lignes->sum('regle'), 2),
            'solde' => round($lignes->sum('solde'), 2),
            'escompte' => round($lignes->sum('escompte'), 2),
            'net' => round($lignes->sum('net'), 2),
        ];
    }

    /** Devise : null pour celle de l'entreprise. */
    private function devise(string $devise): ?string
    {
        $devise = strtoupper(trim($devise));

        return $devise === '' || $devise === strtoupper(trim((string) TenantParametres::query()->value('devise_code'))) ? null : $devise;
    }
}
