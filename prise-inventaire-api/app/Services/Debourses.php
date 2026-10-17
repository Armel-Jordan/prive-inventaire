<?php

namespace App\Services;

use App\Models\CompteBancaire;
use App\Models\ConditionPaiement;
use App\Models\Configuration;
use App\Models\Debourse;
use App\Models\DebourseLigne;
use App\Models\FactureFournisseur;
use App\Models\FactureFournisseurPaiement;
use App\Models\Fournisseur;
use App\Models\TenantParametres;
use App\Models\VirementLot;
use App\Services\Banque\Rapprochements;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Déboursés aux fournisseurs (plan de parité fripv2, phase 4.5), comme l'écran « Déboursé à un fournisseur » de fripv2 :
 * - l'argent versé en une fois (chèque, virement…) se répartit sur les factures dues du fournisseur, dans la devise du
 *   déboursé : chaque part devient un paiement de sa facture ;
 * - l'escompte que le fournisseur accorde pour un paiement rapide (condition de paiement de la facture, « 2/10 Net 30 »)
 *   est proposé facture par facture : le taux sur le montant TTC, tant que le paiement est fait au plus tard à la date
 *   de la facture plus les jours d'escompte et qu'aucun escompte n'a déjà été pris ; il se corrige ;
 * - le montant du déboursé est l'argent versé (parts moins escomptes) ;
 * - un déboursé se corrige (référence, notes) ou s'annule : ses paiements sont retirés des factures. La comptabilisation
 *   automatique (phase 4.3) le passe au journal en une écriture.
 *
 * Le réglé d'une facture fournisseur (montant_paye) compte l'argent versé et les escomptes obtenus.
 */
class Debourses
{
    private const TOLERANCE = 0.005;

    public function relations(): array
    {
        return [
            'fournisseur:id,code,raison_sociale,devise',
            'lignes.facture:id,numero,numero_fournisseur,date_facture,date_echeance,montant_ttc,montant_paye,statut,devise,taux_change',
            'compteBancaire:id,nom,banque', 'auteur:id,nom', 'auteurAnnulation:id,nom',
        ];
    }

    /**
     * Escompte offert sur une facture pour un paiement à une date, ou null si sa condition de paiement n'en prévoit
     * pas : le taux appliqué au montant TTC (au plus ce qui reste dû), jusqu'à la date de la facture plus les jours
     * d'escompte, si aucun escompte n'a déjà été pris sur la facture.
     *
     * @return array{applicable: bool, condition: string, pourcentage: float, date_limite: string, escompte: float}|null
     */
    public function escompte(FactureFournisseur $facture, string $date): ?array
    {
        $condition = $facture->condition_paiement_id ? ConditionPaiement::withTrashed()->find($facture->condition_paiement_id) : null;
        $taux = (float) ($condition?->escompte_pourcentage ?? 0);
        if (! $condition || $taux <= 0) {
            return null;
        }
        $limite = $facture->date_facture->copy()->addDays((int) $condition->escompte_jours)->toDateString();
        $escompte = round(min((float) $facture->montant_ttc * $taux / 100, $facture->reste_a_payer), 2);
        $dejaPris = (float) $facture->paiements()->sum('escompte') > 0;

        return [
            'applicable' => $date <= $limite && ! $dejaPris && $escompte > 0,
            'condition' => $condition->libelle,
            'pourcentage' => $taux,
            'date_limite' => $limite,
            'escompte' => $escompte,
        ];
    }

    /**
     * Ce qui est dû au fournisseur dans une devise (vide : la sienne) : ses factures enregistrées avec un reste à payer,
     * l'échéance la plus ancienne d'abord, avec l'escompte offert pour un paiement à la date donnée.
     */
    public function aPayer(Fournisseur $fournisseur, ?string $devise, string $date): array
    {
        $code = $this->devise($devise ?: $fournisseur->devise);
        $echeance = fn (FactureFournisseur $f) => ($f->date_echeance ?? $f->date_facture)->toDateString();
        $factures = FactureFournisseur::query()->where('fournisseur_id', $fournisseur->id)->where('statut', FactureFournisseur::ENREGISTREE)
            ->whereColumn('montant_paye', '<', 'montant_ttc')->get()
            ->filter(fn (FactureFournisseur $f) => DevisesVente::memeDevise($f->devise, $code))
            ->sortBy([fn (FactureFournisseur $a, FactureFournisseur $b) => $echeance($a) <=> $echeance($b), ['date_facture', 'asc'], ['id', 'asc']])->values();

        return [
            'devise' => $code,
            'factures' => $factures->map(fn (FactureFournisseur $f) => [
                'id' => $f->id, 'numero' => $f->numero, 'numero_fournisseur' => $f->numero_fournisseur,
                'date_facture' => $f->date_facture->toDateString(), 'date_echeance' => $f->date_echeance?->toDateString(),
                'jours_retard' => $echeance($f) < today()->toDateString() ? (int) Carbon::parse($echeance($f))->diffInDays(today()) : 0,
                'montant_ttc' => (float) $f->montant_ttc, 'montant_paye' => (float) $f->montant_paye, 'reste_a_payer' => $f->reste_a_payer,
                'taux_change' => (float) $f->taux_change,
                'escompte' => $this->escompte($f, $date),
            ])->all(),
            'total_du' => round($factures->sum(fn (FactureFournisseur $f) => $f->reste_a_payer), 2),
        ];
    }

    /**
     * Enregistre un déboursé.
     *
     * @param  array{fournisseur_id: int, date_debourse: string, mode: string, reference?: ?string, devise?: ?string, taux_change?: mixed, notes?: ?string, lignes: list<array{facture_fournisseur_id: int, montant: float|string, escompte?: float|string|null}>}  $donnees
     */
    public function enregistrer(array $donnees, int $tenantId, ?int $auteur): Debourse
    {
        $fournisseur = Fournisseur::query()->findOrFail((int) $donnees['fournisseur_id']);
        $devise = $this->devise(array_key_exists('devise', $donnees) ? $donnees['devise'] : $fournisseur->devise);
        $compteBancaire = $this->compteBancaire($donnees['compte_bancaire_id'] ?? null);
        $date = Carbon::parse($donnees['date_debourse'])->toDateString();
        // Les lignes sans rien à payer ne comptent pas (factures proposées, rien porté dessus).
        $lignes = collect($donnees['lignes'] ?? [])
            ->map(fn (array $l) => ['facture_id' => (int) $l['facture_fournisseur_id'], 'argent' => round((float) $l['montant'], 2), 'escompte' => round((float) ($l['escompte'] ?? 0), 2)])
            ->filter(fn (array $l) => $l['argent'] + $l['escompte'] > 0)->values();
        if ($lignes->isEmpty()) {
            throw ValidationException::withMessages(['lignes' => 'Indiquez ce qui est payé sur au moins une facture.']);
        }
        if ($lignes->pluck('facture_id')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['lignes' => 'Une facture ne figure qu\'une fois dans un déboursé.']);
        }
        $montant = round($lignes->sum('argent'), 2);
        if ($montant <= 0) {
            throw ValidationException::withMessages(['lignes' => 'Le déboursé doit verser de l\'argent : un escompte seul ne fait pas un paiement.']);
        }

        return DB::transaction(function () use ($donnees, $tenantId, $auteur, $fournisseur, $devise, $date, $lignes, $montant, $compteBancaire) {
            // Verrous sur les factures, dans l'ordre : deux déboursés simultanés ne paient pas deux fois le même reste.
            $factures = FactureFournisseur::query()->whereIn('id', $lignes->pluck('facture_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($lignes as $ligne) {
                $this->verifierFacture($factures->get($ligne['facture_id']), $fournisseur, $devise, $ligne['argent'] + $ligne['escompte'], $date);
            }
            // Taux du déboursé (totaux dans la devise de l'entreprise) : celui saisi, sinon celui de sa première facture.
            $taux = $devise ? ((float) ($donnees['taux_change'] ?? 0) ?: (float) $factures->first()->taux_change ?: 1.0) : 1.0;

            $numero = $this->numero($tenantId);
            $debourse = Debourse::create([
                'tenant_id' => $tenantId,
                'numero' => $numero,
                'fournisseur_id' => $fournisseur->id,
                'date_debourse' => $date,
                'mode' => $donnees['mode'],
                'reference' => $this->texte($donnees['reference'] ?? null),
                'devise' => $devise,
                'taux_change' => $taux,
                'montant' => $montant,
                'montant_applique' => round($lignes->sum(fn ($l) => $l['argent'] + $l['escompte']), 2),
                'escompte' => round($lignes->sum('escompte'), 2),
                'compte_bancaire_id' => $compteBancaire?->id,
                'notes' => $this->texte($donnees['notes'] ?? null),
                'statut' => Debourse::VALIDE,
                'enregistre_par' => $auteur,
            ]);

            foreach ($lignes as $ligne) {
                $facture = $factures->get($ligne['facture_id']);
                // Un paiement de la facture comme un autre, rattaché au déboursé ; l'escompte obtenu la règle aussi.
                $paiement = FactureFournisseurPaiement::create([
                    'tenant_id' => $tenantId,
                    'facture_fournisseur_id' => $facture->id,
                    'debourse_id' => $debourse->id,
                    'date_paiement' => $date,
                    'montant' => $ligne['argent'],
                    'escompte' => $ligne['escompte'],
                    'mode' => $debourse->mode,
                    'reference' => mb_substr($debourse->reference ?: $numero, 0, 60),
                    'notes' => "Déboursé {$numero}",
                    'enregistre_par' => $auteur,
                ]);
                $facture->update(['montant_paye' => round((float) $facture->montant_paye + $ligne['argent'] + $ligne['escompte'], 2)]);
                DebourseLigne::create([
                    'debourse_id' => $debourse->id, 'facture_fournisseur_id' => $facture->id,
                    'montant' => round($ligne['argent'] + $ligne['escompte'], 2), 'escompte' => $ligne['escompte'], 'paiement_id' => $paiement->id,
                ]);
            }

            return $debourse->fresh($this->relations());
        });
    }

    /**
     * Paie une sélection de factures en un geste (rapport des paiements dus) : un déboursé par fournisseur et par
     * devise, qui règle le reste de chaque facture, moins l'escompte encore offert à la date du paiement (si demandé).
     * Tout ou rien : un refus n'en crée aucun.
     *
     * @param  array{factures: list<int>, date_debourse: string, mode: string, reference?: ?string, notes?: ?string, escompte?: bool}  $donnees
     * @return Collection<int, Debourse>
     */
    public function payerLot(array $donnees, int $tenantId, ?int $auteur): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $donnees['factures'])));
        $date = Carbon::parse($donnees['date_debourse'])->toDateString();

        return DB::transaction(function () use ($ids, $date, $donnees, $tenantId, $auteur) {
            $factures = FactureFournisseur::query()->whereIn('id', $ids)->orderBy('id')->get();
            if ($factures->count() !== count($ids)) {
                throw ValidationException::withMessages(['factures' => "Choisissez des factures fournisseurs de l'entreprise."]);
            }
            foreach ($factures as $facture) {
                if ($facture->statut !== FactureFournisseur::ENREGISTREE || $facture->reste_a_payer <= 0) {
                    throw ValidationException::withMessages(['factures' => "La facture {$facture->numero} ne reste pas à payer."]);
                }
            }
            $creees = collect();
            foreach ($factures->groupBy(fn (FactureFournisseur $f) => $f->fournisseur_id.'|'.($f->devise ?? '')) as $groupe) {
                $lignes = $groupe->map(function (FactureFournisseur $f) use ($date, $donnees) {
                    $offre = ($donnees['escompte'] ?? true) ? $this->escompte($f, $date) : null;
                    $escompte = $offre && $offre['applicable'] ? $offre['escompte'] : 0.0;

                    return ['facture_fournisseur_id' => $f->id, 'montant' => round($f->reste_a_payer - $escompte, 2), 'escompte' => $escompte];
                })->values()->all();
                $creees->push($this->enregistrer([
                    'fournisseur_id' => $groupe->first()->fournisseur_id,
                    'date_debourse' => $date,
                    'mode' => $donnees['mode'],
                    'reference' => $donnees['reference'] ?? null,
                    'compte_bancaire_id' => $donnees['compte_bancaire_id'] ?? null,
                    'devise' => $groupe->first()->devise,
                    'notes' => $donnees['notes'] ?? null,
                    'lignes' => $lignes,
                ], $tenantId, $auteur));
            }

            return $creees;
        });
    }

    /** Corrige la référence et les notes d'un déboursé (le reste se corrige en l'annulant puis en le refaisant). */
    public function modifier(Debourse $debourse, array $donnees): Debourse
    {
        if ($debourse->statut === Debourse::ANNULE) {
            throw new DomainException("Le déboursé {$debourse->numero} est annulé : il ne se modifie plus.");
        }

        return DB::transaction(function () use ($debourse, $donnees) {
            $debourse->update([
                'reference' => array_key_exists('reference', $donnees) ? $this->texte($donnees['reference']) : $debourse->reference,
                'notes' => array_key_exists('notes', $donnees) ? $this->texte($donnees['notes']) : $debourse->notes,
            ]);
            // Les paiements des factures portent la référence du déboursé (sinon son numéro).
            FactureFournisseurPaiement::query()->where('debourse_id', $debourse->id)
                ->update(['reference' => mb_substr($debourse->reference ?: $debourse->numero, 0, 60)]);

            return $debourse->load($this->relations());
        });
    }

    /**
     * Annule un déboursé saisi par erreur : ses paiements (argent et escomptes) sont retirés des factures, qui
     * redeviennent dues d'autant. L'écriture déjà passée au journal sera contre-passée.
     */
    public function annuler(Debourse $debourse, ?string $motif, ?int $auteur): Debourse
    {
        return DB::transaction(function () use ($debourse, $motif, $auteur) {
            $debourse = Debourse::query()->whereKey($debourse->id)->lockForUpdate()->firstOrFail();
            if ($debourse->statut === Debourse::ANNULE) {
                throw new DomainException("Le déboursé {$debourse->numero} est déjà annulé.");
            }
            // Un virement transmis à la banque peut encore être exécuté : le lot doit d'abord être marqué refusé.
            if ($debourse->virement_statut === VirementLot::EMIS) {
                throw new DomainException("Le déboursé {$debourse->numero} est dans le lot de virements n° {$debourse->virementLot?->numero} transmis à la banque : marquez le lot refusé avant de l'annuler.");
            }
            app(Rapprochements::class)->verifierDocument('debourse', $debourse->id, "Le déboursé {$debourse->numero}");
            $paiements = FactureFournisseurPaiement::query()->where('debourse_id', $debourse->id)->get();
            $factures = FactureFournisseur::query()->whereIn('id', $paiements->pluck('facture_fournisseur_id'))->orderBy('id')->lockForUpdate()->get();
            foreach ($factures as $facture) {
                $retire = $paiements->where('facture_fournisseur_id', $facture->id)->sum(fn ($p) => (float) $p->montant + (float) $p->escompte);
                $facture->update(['montant_paye' => max(0, round((float) $facture->montant_paye - $retire, 2))]);
            }
            FactureFournisseurPaiement::query()->where('debourse_id', $debourse->id)->delete();
            $debourse->update([
                'statut' => Debourse::ANNULE,
                'annule_le' => now(),
                'annule_par' => $auteur,
                'motif_annulation' => $this->texte($motif),
            ]);

            return $debourse->load($this->relations());
        });
    }

    /** Totaux d'une liste de déboursés valides, dans la devise de l'entreprise. */
    public function resume(Builder $requete): array
    {
        $valides = $requete->where('statut', Debourse::VALIDE)->get(['montant', 'escompte', 'taux_change']);
        $total = fn (string $champ) => round($valides->sum(fn (Debourse $d) => (float) $d->{$champ} * ((float) $d->taux_change ?: 1)), 2);

        return ['nombre' => $valides->count(), 'montant' => $total('montant'), 'escompte' => $total('escompte')];
    }

    /** Une facture que le déboursé peut payer : du fournisseur, enregistrée, dans sa devise, sans dépasser son reste. */
    private function verifierFacture(?FactureFournisseur $facture, Fournisseur $fournisseur, ?string $devise, float $porte, string $date): void
    {
        if (! $facture || $facture->fournisseur_id !== $fournisseur->id) {
            throw ValidationException::withMessages(['lignes' => 'Choisissez des factures du fournisseur.']);
        }
        if ($facture->statut !== FactureFournisseur::ENREGISTREE) {
            throw ValidationException::withMessages(['lignes' => "La facture {$facture->numero} est annulée."]);
        }
        if (! DevisesVente::memeDevise($facture->devise, $devise)) {
            throw ValidationException::withMessages(['lignes' => "La facture {$facture->numero} n'est pas dans la devise du déboursé."]);
        }
        if ($porte > $facture->reste_a_payer + self::TOLERANCE) {
            throw ValidationException::withMessages(['lignes' => "Le paiement et l'escompte dépassent le reste à payer de la facture {$facture->numero} ({$this->montant($facture->reste_a_payer)})."]);
        }
        if ($date < $facture->date_facture->toDateString()) {
            throw ValidationException::withMessages(['date_debourse' => "Le déboursé ne peut pas précéder la facture {$facture->numero} ({$facture->date_facture->format('d/m/Y')})."]);
        }
    }

    /** Compte bancaire choisi : actif, sinon refusé ; vide : aucun (le compte de banque des réglages en comptabilité). */
    private function compteBancaire(mixed $id): ?CompteBancaire
    {
        if (empty($id)) {
            return null;
        }

        return CompteBancaire::query()->where('actif', true)->find((int) $id)
            ?? throw ValidationException::withMessages(['compte_bancaire_id' => 'Choisissez un compte bancaire actif.']);
    }

    private function numero(int $tenantId): string
    {
        $config = Configuration::pourEntite('debourse', $tenantId);
        if (! $config?->auto_increment) {
            throw new DomainException('Numéro requis : activez la numérotation automatique des déboursés (Configuration → Numérotation).');
        }

        return Configuration::consommerNumero('debourse', $tenantId);
    }

    /** Devise : vide pour celle de l'entreprise. */
    private function devise(?string $devise): ?string
    {
        $devise = strtoupper(trim((string) $devise));

        return $devise === '' || $devise === strtoupper(trim((string) TenantParametres::query()->value('devise_code'))) ? null : $devise;
    }

    private function texte(mixed $valeur): ?string
    {
        $texte = trim((string) $valeur);

        return $texte === '' ? null : $texte;
    }

    private function montant(mixed $valeur): string
    {
        return number_format((float) $valeur, 2, ',', ' ');
    }
}
