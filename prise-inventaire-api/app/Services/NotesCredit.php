<?php

namespace App\Services;

use App\Models\Avoir;
use App\Models\AvoirImputation;
use App\Models\AvoirLigne;
use App\Models\AvoirRemboursement;
use App\Models\Configuration;
use App\Models\DocumentTaxe;
use App\Models\Facture;
use App\Models\FactureLigne;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Notes de crédit (avoirs), comme le « Créditer » d'une facture dans fripv2 (plan de parité, phase 1.5).
 *
 * - Une note crédite des lignes d'une facture émise : quantité et prix unitaire au plus ceux facturés, remise de
 *   la ligne reprise, taxes au prorata de celles calculées sur la facture (même détail par taxe). Le montant HT
 *   crédité d'une ligne, toutes notes émises comprises, ne dépasse jamais celui facturé. La note qui crédite
 *   tout ce qui restait reprend exactement les totaux restants de la facture (pas d'écart d'arrondi).
 * - Brouillon modifiable, puis émise : elle reçoit son numéro (numérotation « avoir ») et s'impute sur ce qui
 *   reste dû de sa facture.
 * - Le crédit encore disponible (facture déjà payée) se rembourse au client ou s'impute sur une autre de ses
 *   factures dues. Entièrement utilisée, la note est soldée.
 *
 * Financier seulement : une note de crédit ne remet rien en stock.
 */
class NotesCredit
{
    /** Factures qu'on peut créditer : émises, payées ou non. */
    public const FACTURES_CREDITABLES = ['emise', 'partiellement_payee', 'payee'];

    /** Factures sur lesquelles imputer un crédit : celles qui restent dues. */
    public const FACTURES_DUES = ['emise', 'partiellement_payee'];

    public const MODES_REMBOURSEMENT = ['especes', 'cheque', 'virement', 'carte', 'autre'];

    private const TOLERANCE = 0.005;

    public function __construct(private readonly TaxesDocument $taxes) {}

    /**
     * Ce qu'on peut encore créditer sur une facture : chaque ligne avec le montant HT déjà crédité par les notes
     * émises, le reste, et une quantité proposée au prix facturé (tout le reste pour une note complète).
     */
    public function proposition(Facture $facture): array
    {
        $facture->loadMissing(['client:id,code,raison_sociale', 'lignes.produit' => fn ($q) => $q->withTrashed()->select('id', 'numero', 'description', 'mesure')]);
        $credites = $this->dejaCredite($facture);
        $emis = $this->totauxEmis($facture);

        return [
            'facture' => $facture->only(['id', 'numero', 'statut', 'client_id', 'montant_ht', 'montant_tva', 'montant_ttc', 'montant_paye', 'montant_credite', 'reste_a_payer'])
                + ['date_facture' => $facture->date_facture?->toDateString(), 'client' => $facture->client?->only(['id', 'code', 'raison_sociale'])],
            'creditable' => in_array($facture->statut, self::FACTURES_CREDITABLES, true),
            'deja_credite' => $emis,
            'reste_ttc' => max(0.0, round((float) $facture->montant_ttc - $emis['ttc'], 2)),
            'lignes' => $facture->lignes->map(function (FactureLigne $ligne) use ($credites) {
                $deja = $credites[$ligne->id] ?? ['ht' => 0.0, 'quantite' => 0];
                $resteHt = max(0.0, round((float) $ligne->montant_ht - $deja['ht'], 2));
                $unitaire = (float) $ligne->prix_unitaire_ht * (1 - (float) $ligne->remise_ligne / 100);
                $proposee = $unitaire > 0
                    ? min((int) $ligne->quantite, (int) floor($resteHt / $unitaire + 1e-6))
                    : max(0, (int) $ligne->quantite - $deja['quantite']);

                return [
                    'facture_ligne_id' => $ligne->id,
                    'produit_id' => $ligne->produit_id,
                    'produit' => $ligne->produit?->only(['id', 'numero', 'description', 'mesure']),
                    'quantite_facturee' => (int) $ligne->quantite,
                    'prix_unitaire_ht' => (float) $ligne->prix_unitaire_ht,
                    'remise_ligne' => (float) $ligne->remise_ligne,
                    'taux_tva' => (float) $ligne->taux_tva,
                    'montant_ht' => (float) $ligne->montant_ht,
                    'quantite_creditee' => $deja['quantite'],
                    'credite_ht' => round($deja['ht'], 2),
                    'reste_ht' => $resteHt,
                    'quantite_proposee' => $proposee,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Enregistre une note brouillon : nouvelle, ou remplace le contenu d'un brouillon existant.
     *
     * @param  array{date_avoir: string, motif?: ?string, notes?: ?string, lignes: list<array{facture_ligne_id: int, quantite: int, prix_unitaire_ht?: float|null}>}  $donnees
     */
    public function enregistrer(Facture $facture, array $donnees, ?Avoir $avoir = null, ?int $auteur = null): Avoir
    {
        if ($avoir && $avoir->statut !== Avoir::BROUILLON) {
            throw new DomainException('Une note de crédit émise ne se modifie plus.');
        }
        $this->verifierCreditable($facture);
        if ($donnees['date_avoir'] < $facture->date_facture->toDateString()) {
            throw ValidationException::withMessages(['date_avoir' => 'La note de crédit ne peut pas précéder la facture ('.$facture->date_facture->format('d/m/Y').').']);
        }
        $facture->loadMissing('lignes');
        $lignes = $this->calculerLignes($facture, $donnees['lignes'], $avoir?->id);
        $totaux = $this->totaux($facture, $lignes, $avoir?->id);
        if ($totaux['ttc'] <= 0) {
            throw ValidationException::withMessages(['lignes' => 'La note de crédit doit porter sur un montant.']);
        }

        return DB::transaction(function () use ($facture, $donnees, $avoir, $auteur, $lignes, $totaux) {
            $entete = [
                'date_avoir' => $donnees['date_avoir'],
                'motif' => $donnees['motif'] ?? null,
                'notes' => $donnees['notes'] ?? null,
                'montant_ht' => $totaux['ht'],
                'montant_tva' => $totaux['tva'],
                'montant_ttc' => $totaux['ttc'],
            ];
            if ($avoir) {
                $this->taxes->supprimer('avoir', $avoir->lignes()->pluck('id'));
                $avoir->lignes()->delete();
                $avoir->update($entete);
            } else {
                $avoir = Avoir::create($entete + [
                    'facture_id' => $facture->id,
                    'client_id' => $facture->client_id,
                    'statut' => Avoir::BROUILLON,
                    'cree_par' => $auteur,
                ]);
            }
            foreach ($lignes as $ligne) {
                $creee = AvoirLigne::create(['avoir_id' => $avoir->id] + array_diff_key($ligne, ['detail' => true]));
                $this->taxes->enregistrer('avoir', $creee->id, $ligne['detail']);
            }

            return $avoir;
        });
    }

    /**
     * Émet une note brouillon : contrôle de nouveau ce qui reste à créditer (une autre note a pu être émise
     * depuis), numéro, puis imputation sur ce qui reste dû de la facture.
     */
    public function emettre(Avoir $avoir, ?int $auteur = null): Avoir
    {
        $config = Configuration::pourEntite('avoir', $avoir->tenant_id);
        if (! $config || ! $config->auto_increment) {
            throw new DomainException('Numéro requis : activez la numérotation automatique des notes de crédit (Configuration → Numérotation).');
        }

        return DB::transaction(function () use ($avoir, $auteur) {
            // Verrous : deux émissions simultanées sur la même facture ne peuvent pas la créditer deux fois.
            $facture = Facture::with('lignes')->whereKey($avoir->facture_id)->lockForUpdate()->firstOrFail();
            $avoir = Avoir::with('lignes')->whereKey($avoir->id)->lockForUpdate()->firstOrFail();
            if ($avoir->statut !== Avoir::BROUILLON) {
                throw new DomainException('Cette note de crédit est déjà émise.');
            }
            $this->verifierCreditable($facture);
            $lignes = $this->calculerLignes($facture, $avoir->lignes->map(fn (AvoirLigne $l) => [
                'facture_ligne_id' => $l->facture_ligne_id, 'quantite' => $l->quantite, 'prix_unitaire_ht' => (float) $l->prix_unitaire_ht,
            ])->all(), $avoir->id);
            $totaux = $this->totaux($facture, $lignes, $avoir->id);

            $avoir->fill([
                'numero' => Configuration::consommerNumero('avoir', $avoir->tenant_id),
                'statut' => Avoir::EMIS,
                'montant_ht' => $totaux['ht'],
                'montant_tva' => $totaux['tva'],
                'montant_ttc' => $totaux['ttc'],
                'emis_le' => now(),
                'emis_par' => $auteur,
            ])->save();

            $impute = round(min((float) $avoir->montant_ttc, max(0.0, (float) $facture->reste_a_payer)), 2);
            if ($impute > 0 && in_array($facture->statut, self::FACTURES_DUES, true)) {
                AvoirImputation::create([
                    'avoir_id' => $avoir->id, 'facture_id' => $facture->id, 'date_imputation' => $avoir->date_avoir,
                    'montant' => $impute, 'enregistre_par' => $auteur,
                ]);
            }
            $this->actualiser($avoir);
            $facture->updateMontantPaye();

            return $avoir;
        });
    }

    /** Impute une part du crédit disponible sur une facture due du même client. */
    public function imputer(Avoir $avoir, int $factureId, float $montant, string $date, ?int $auteur = null): Avoir
    {
        return DB::transaction(function () use ($avoir, $factureId, $montant, $date, $auteur) {
            $avoir = Avoir::whereKey($avoir->id)->lockForUpdate()->firstOrFail();
            $facture = Facture::whereKey($factureId)->lockForUpdate()->first();
            $this->verifierDisponible($avoir, $montant, $date, 'date');
            if (! $facture || $facture->client_id !== $avoir->client_id) {
                throw ValidationException::withMessages(['facture_id' => 'Choisissez une facture du même client.']);
            }
            if (! in_array($facture->statut, self::FACTURES_DUES, true)) {
                throw ValidationException::withMessages(['facture_id' => "La facture {$facture->numero} ne reste pas due."]);
            }
            if ($montant > (float) $facture->reste_a_payer + self::TOLERANCE) {
                throw ValidationException::withMessages(['montant' => 'Le montant dépasse le reste à payer de la facture ('.$this->montant($facture->reste_a_payer).').']);
            }
            if ($date < $facture->date_facture->toDateString()) {
                throw ValidationException::withMessages(['date' => 'L\'imputation ne peut pas précéder la facture ('.$facture->date_facture->format('d/m/Y').').']);
            }

            AvoirImputation::create([
                'avoir_id' => $avoir->id, 'facture_id' => $facture->id, 'date_imputation' => $date,
                'montant' => round($montant, 2), 'enregistre_par' => $auteur,
            ]);
            $this->actualiser($avoir);
            $facture->updateMontantPaye();

            return $avoir;
        });
    }

    /**
     * Rembourse au client une part du crédit disponible.
     *
     * @param  array{montant: float|string, date_remboursement: string, mode?: ?string, reference?: ?string, notes?: ?string}  $donnees
     */
    public function rembourser(Avoir $avoir, array $donnees, ?int $auteur = null): Avoir
    {
        return DB::transaction(function () use ($avoir, $donnees, $auteur) {
            $avoir = Avoir::whereKey($avoir->id)->lockForUpdate()->firstOrFail();
            $this->verifierDisponible($avoir, (float) $donnees['montant'], $donnees['date_remboursement'], 'date_remboursement');

            AvoirRemboursement::create([
                'avoir_id' => $avoir->id,
                'date_remboursement' => $donnees['date_remboursement'],
                'montant' => round((float) $donnees['montant'], 2),
                'mode' => $donnees['mode'] ?? null,
                'reference' => $donnees['reference'] ?? null,
                'notes' => $donnees['notes'] ?? null,
                'enregistre_par' => $auteur,
            ]);
            $this->actualiser($avoir);

            return $avoir;
        });
    }

    /** Supprime une note brouillon (une note émise reste : sa numérotation est continue). */
    public function supprimer(Avoir $avoir): void
    {
        if ($avoir->statut !== Avoir::BROUILLON) {
            throw new DomainException('Seules les notes de crédit en brouillon peuvent être supprimées.');
        }
        DB::transaction(function () use ($avoir) {
            $this->taxes->supprimer('avoir', $avoir->lignes()->pluck('id'));
            $avoir->delete();
        });
    }

    /**
     * Lignes de la note calculées depuis celles de la facture : montant HT au prix saisi (remise de la ligne
     * facturée comprise), taxes au prorata. Refuse ce qui dépasse ce qui reste à créditer sur une ligne.
     */
    private function calculerLignes(Facture $facture, array $saisies, ?int $sauf): array
    {
        $lignesFacture = $facture->lignes->keyBy('id');
        $credites = $this->dejaCredite($facture, $sauf);
        $calculees = [];
        foreach (array_values($saisies) as $index => $saisie) {
            $ligne = $lignesFacture[(int) $saisie['facture_ligne_id']] ?? null;
            if (! $ligne) {
                throw ValidationException::withMessages(["lignes.{$index}.facture_ligne_id" => "Cette ligne n'appartient pas à la facture {$facture->numero}."]);
            }
            $quantite = (int) $saisie['quantite'];
            $prix = round((float) ($saisie['prix_unitaire_ht'] ?? $ligne->prix_unitaire_ht), 2);
            if ($quantite > (int) $ligne->quantite) {
                throw ValidationException::withMessages(["lignes.{$index}.quantite" => "Au plus {$ligne->quantite} (quantité facturée)."]);
            }
            if ($prix > (float) $ligne->prix_unitaire_ht + 0.0001) {
                throw ValidationException::withMessages(["lignes.{$index}.prix_unitaire_ht" => 'Au plus le prix facturé ('.$this->montant($ligne->prix_unitaire_ht).').']);
            }
            $remise = (float) $ligne->remise_ligne;
            $ht = CalculTaxes::arrondir($quantite * $prix * (1 - $remise / 100));
            $reste = round((float) $ligne->montant_ht - ($credites[$ligne->id]['ht'] ?? 0.0), 2);
            if ($ht > $reste + self::TOLERANCE) {
                throw ValidationException::withMessages(["lignes.{$index}.quantite" => 'Dépasse ce qui reste à créditer sur cette ligne ('.$this->montant(max(0.0, $reste)).' HT).']);
            }
            [$tva, $detail] = $this->taxesAuProrata($ligne, $ht);
            $calculees[] = [
                'facture_ligne_id' => $ligne->id,
                'produit_id' => $ligne->produit_id,
                'quantite' => $quantite,
                'prix_unitaire_ht' => $prix,
                'taux_tva' => (float) $ligne->taux_tva,
                'groupe_taxe_id' => $ligne->groupe_taxe_id,
                'remise_ligne' => $remise,
                'montant_ht' => $ht,
                'montant_tva' => $tva,
                'montant_ttc' => round($ht + $tva, 2),
                'detail' => $detail,
            ];
        }

        return $calculees;
    }

    /**
     * Taxes d'une ligne de note : détail de la ligne facturée au prorata du montant crédité (même nom et même taux
     * qu'à la facturation, même si le groupe de taxes a changé depuis), sinon son taux unique.
     *
     * @return array{float, ?array}
     */
    private function taxesAuProrata(FactureLigne $ligne, float $ht): array
    {
        $facturees = DocumentTaxe::where('document', 'facture')->where('ligne_id', $ligne->id)->orderBy('id')->get();
        if ($facturees->isEmpty()) {
            return [CalculTaxes::arrondir($ht * (float) $ligne->taux_tva / 100), null];
        }
        $ratio = (float) $ligne->montant_ht > 0 ? $ht / (float) $ligne->montant_ht : 0.0;
        $taxes = $facturees->map(fn (DocumentTaxe $taxe) => [
            'taxe_id' => $taxe->tenant_taxe_id,
            'nom' => $taxe->nom,
            'taux' => (float) $taxe->taux,
            'applique_sur' => $taxe->applique_sur,
            'base' => CalculTaxes::arrondir((float) $taxe->base * $ratio),
            'montant' => CalculTaxes::arrondir((float) $taxe->montant * $ratio),
        ])->all();

        return [round(array_sum(array_column($taxes, 'montant')), 2), ['taxes' => $taxes]];
    }

    /**
     * Totaux de la note : somme de ses lignes ; la note qui crédite tout ce qui restait reprend exactement les
     * totaux restants de la facture.
     *
     * @return array{ht: float, tva: float, ttc: float}
     */
    private function totaux(Facture $facture, array $lignes, ?int $sauf): array
    {
        $ht = round(array_sum(array_column($lignes, 'montant_ht')), 2);
        $tva = round(array_sum(array_column($lignes, 'montant_tva')), 2);
        $credites = $this->dejaCredite($facture, $sauf);
        $parLigne = [];
        foreach ($lignes as $ligne) {
            $parLigne[$ligne['facture_ligne_id']] = ($parLigne[$ligne['facture_ligne_id']] ?? 0.0) + $ligne['montant_ht'];
        }
        $complete = $facture->lignes->every(fn (FactureLigne $l) => ($credites[$l->id]['ht'] ?? 0.0) + ($parLigne[$l->id] ?? 0.0) >= (float) $l->montant_ht - self::TOLERANCE);
        if ($complete) {
            $emis = $this->totauxEmis($facture, $sauf);

            return [
                'ht' => round((float) $facture->montant_ht - $emis['ht'], 2),
                'tva' => round((float) $facture->montant_tva - $emis['tva'], 2),
                'ttc' => round((float) $facture->montant_ttc - $emis['ttc'], 2),
            ];
        }

        return ['ht' => $ht, 'tva' => $tva, 'ttc' => round($ht + $tva, 2)];
    }

    /**
     * Montant HT et quantité déjà crédités par ligne de facture (notes émises, sauf celle indiquée).
     *
     * @return array<int, array{ht: float, quantite: int}>
     */
    private function dejaCredite(Facture $facture, ?int $sauf = null): array
    {
        return AvoirLigne::query()
            ->join('avoirs', 'avoirs.id', '=', 'avoir_lignes.avoir_id')
            ->where('avoirs.facture_id', $facture->id)->whereIn('avoirs.statut', Avoir::EMISES)
            ->when($sauf, fn ($q) => $q->where('avoirs.id', '!=', $sauf))
            ->groupBy('avoir_lignes.facture_ligne_id')
            ->selectRaw('avoir_lignes.facture_ligne_id AS ligne, SUM(avoir_lignes.montant_ht) AS ht, SUM(avoir_lignes.quantite) AS quantite')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->ligne => ['ht' => (float) $r->ht, 'quantite' => (int) $r->quantite]])
            ->all();
    }

    /** @return array{ht: float, tva: float, ttc: float} */
    private function totauxEmis(Facture $facture, ?int $sauf = null): array
    {
        $totaux = Avoir::where('facture_id', $facture->id)->whereIn('statut', Avoir::EMISES)
            ->when($sauf, fn ($q) => $q->whereKeyNot($sauf))
            ->selectRaw('COALESCE(SUM(montant_ht), 0) AS ht, COALESCE(SUM(montant_tva), 0) AS tva, COALESCE(SUM(montant_ttc), 0) AS ttc')
            ->first();

        return ['ht' => round((float) $totaux->ht, 2), 'tva' => round((float) $totaux->tva, 2), 'ttc' => round((float) $totaux->ttc, 2)];
    }

    private function verifierCreditable(Facture $facture): void
    {
        if (! in_array($facture->statut, self::FACTURES_CREDITABLES, true)) {
            throw new DomainException($facture->statut === 'creditee'
                ? "La facture {$facture->numero} est déjà entièrement créditée."
                : 'Seule une facture émise peut être créditée.');
        }
    }

    private function verifierDisponible(Avoir $avoir, float $montant, string $date, string $champDate): void
    {
        if ($avoir->statut === Avoir::BROUILLON) {
            throw new DomainException("Émettez d'abord la note de crédit.");
        }
        if ($avoir->statut !== Avoir::EMIS || $avoir->credit_disponible <= 0) {
            throw new DomainException("La note de crédit {$avoir->numero} n'a plus de crédit disponible.");
        }
        if ($montant > $avoir->credit_disponible + self::TOLERANCE) {
            throw ValidationException::withMessages(['montant' => 'Le montant dépasse le crédit disponible ('.$this->montant($avoir->credit_disponible).').']);
        }
        if ($date < $avoir->date_avoir->toDateString()) {
            throw ValidationException::withMessages([$champDate => 'La date ne peut pas précéder la note de crédit ('.$avoir->date_avoir->format('d/m/Y').').']);
        }
    }

    /** Totaux imputés et remboursés de la note ; soldée quand il ne reste plus de crédit. */
    private function actualiser(Avoir $avoir): void
    {
        $avoir->montant_impute = $avoir->imputations()->sum('montant');
        $avoir->montant_rembourse = $avoir->remboursements()->sum('montant');
        $disponible = round((float) $avoir->montant_ttc - (float) $avoir->montant_impute - (float) $avoir->montant_rembourse, 2);
        $avoir->statut = $disponible <= self::TOLERANCE ? Avoir::SOLDE : Avoir::EMIS;
        $avoir->save();
    }

    private function montant(mixed $valeur): string
    {
        return number_format((float) $valeur, 2, ',', ' ');
    }
}
