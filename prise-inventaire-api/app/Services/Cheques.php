<?php

namespace App\Services;

use App\Models\CompteBancaire;
use App\Models\Debourse;
use App\Models\DebourseLigne;
use App\Models\FournisseurAdresse;
use App\Support\EntreprisePdf;
use App\Support\MontantEnLettres;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentPdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Chèques des déboursés payés par chèque (plan de parité fripv2, phase 4.5, « Rapport d'impression de chèque ») :
 * - numéroter : un déboursé sans référence prend le prochain numéro du chéquier de son compte bancaire, qui avance (un
 *   numéro déjà porté par un chèque de ce compte est sauté) ; une référence existante est gardée, pour réimprimer le
 *   même chèque ; la première impression est notée (date, auteur) ;
 * - le chèque, une page par déboursé : le bénéficiaire et son adresse principale, la date, le montant en chiffres et en
 *   lettres (français ou anglais) entre astérisques, puis deux talons (fournisseur, entreprise) : les factures payées
 *   (20 au plus, regroupées par facture), escompte et net, et leurs totaux.
 */
class Cheques
{
    public const MAX_TALON = 20;

    public function __construct(private readonly DevisesVente $devises) {}

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, numero: string, cheque: string, nouveau: bool}>
     */
    public function numeroter(array $ids, ?int $auteur): array
    {
        $debourses = $this->debourses($ids);
        foreach ($debourses as $debourse) {
            $refus = match (true) {
                $debourse->statut !== Debourse::VALIDE => __('Le déboursé :numero est annulé.', ['numero' => $debourse->numero]),
                $debourse->mode !== 'cheque' => __("Le déboursé :numero n'est pas payé par chèque.", ['numero' => $debourse->numero]),
                (float) $debourse->montant <= 0 => __("Le déboursé :numero n'a pas de montant à payer.", ['numero' => $debourse->numero]),
                ! $debourse->reference && ! $debourse->compteBancaire => __('Choisissez le compte bancaire du déboursé :numero : le chèque est tiré sur son chéquier.', ['numero' => $debourse->numero]),
                ! $debourse->reference && ! $debourse->compteBancaire->prochain_cheque => __('Indiquez le prochain numéro de chèque du compte « :nom » (Finance → Banque → Comptes).', ['nom' => $debourse->compteBancaire->nom]),
                default => null,
            };
            if ($refus) {
                throw ValidationException::withMessages(['debourses' => $refus]);
            }
        }

        return DB::transaction(function () use ($debourses, $auteur) {
            $resultat = [];
            foreach ($debourses as $debourse) {
                $nouveau = ! $debourse->reference;
                if ($nouveau) {
                    $compte = CompteBancaire::query()->lockForUpdate()->findOrFail($debourse->compte_bancaire_id);
                    $numero = (int) $compte->prochain_cheque;
                    while (Debourse::query()->where('compte_bancaire_id', $compte->id)->where('mode', 'cheque')->where('reference', (string) $numero)->exists()) {
                        $numero++;
                    }
                    $debourse->reference = (string) $numero;
                    $compte->update(['prochain_cheque' => $numero + 1]);
                }
                if (! $debourse->cheque_imprime_le) {
                    $debourse->cheque_imprime_le = now();
                    $debourse->cheque_imprime_par = $auteur;
                }
                $debourse->save();
                $resultat[] = ['id' => $debourse->id, 'numero' => $debourse->numero, 'cheque' => $debourse->reference, 'nouveau' => $nouveau];
            }

            return $resultat;
        });
    }

    /** Les chèques numérotés de déboursés, un par page, en français ou en anglais. */
    public function pdf(array $ids, string $langue): DocumentPdf
    {
        return Pdf::loadView('pdf.cheque', ['cheques' => $this->cheques($ids, $langue), 'langue' => $langue] + EntreprisePdf::entete())
            ->setPaper('A4', 'portrait')
            ->setOption('isFontSubsettingEnabled', true);
    }

    /** Ce que porte chaque chèque : bénéficiaire, adresse, montants en chiffres et en lettres, talon des factures. */
    public function cheques(array $ids, string $langue): Collection
    {
        return $this->debourses($ids)->map(function (Debourse $debourse) use ($langue) {
            if ($debourse->mode !== 'cheque' || ! $debourse->reference || ! $debourse->cheque_imprime_le) {
                throw ValidationException::withMessages(['debourses' => __("Numérotez d'abord le chèque du déboursé :numero.", ['numero' => $debourse->numero])]);
            }

            return $this->cheque($debourse, $langue);
        });
    }

    private function cheque(Debourse $debourse, string $langue): array
    {
        $devise = $debourse->devise ?: $this->devises->reference();
        // Plusieurs montants portés sur une même facture : une seule ligne ; par date de facture puis numéro.
        $factures = $debourse->lignes->groupBy('facture_fournisseur_id')->map(function (Collection $lignes) {
            $facture = $lignes->first()->facture;
            $montant = (float) $lignes->sum(fn (DebourseLigne $l) => (float) $l->montant);
            $escompte = (float) $lignes->sum(fn (DebourseLigne $l) => (float) $l->escompte);

            return [
                'date' => $facture?->date_facture?->toDateString(),
                'numero' => $facture?->numero_fournisseur ?: $facture?->numero,
                'escompte' => round($escompte, 2),
                'net' => round($montant - $escompte, 2),
            ];
        })->sortBy(fn (array $f) => ($f['date'] ?? '').'|'.$f['numero'])->values();
        $fournisseur = $debourse->fournisseur;
        $adresse = $fournisseur?->adresses->firstWhere('actif', true) ?? $fournisseur?->adresses->first();

        return [
            'debourse' => $debourse,
            'numero' => $debourse->reference,
            'date' => $debourse->date_debourse->toDateString(),
            'montant' => (float) $debourse->montant,
            'decimales' => MontantEnLettres::decimales($devise),
            'devise' => $devise,
            'symbole' => $this->devises->symbole($debourse->devise ?: null),
            'lettres' => MontantEnLettres::ecrire((float) $debourse->montant, $devise, $langue),
            'beneficiaire' => (string) $fournisseur?->raison_sociale,
            'code' => (string) $fournisseur?->code,
            'adresse' => $adresse instanceof FournisseurAdresse
                ? array_values(array_filter([$adresse->adresse, trim(($adresse->code_postal ?? '').' '.($adresse->ville ?? '')), $adresse->region, $adresse->pays]))
                : array_values(array_filter(preg_split('/\r\n|\r|\n/', (string) $fournisseur?->adresse))),
            'compte' => $debourse->compteBancaire,
            'factures' => $factures->take(self::MAX_TALON)->all(),
            'autres' => max(0, $factures->count() - self::MAX_TALON),
            'total_escompte' => round((float) $factures->sum('escompte'), 2),
            'total_net' => round((float) $factures->sum('net'), 2),
        ];
    }

    /** Déboursés demandés, dans l'ordre de leur date puis de leur numéro. */
    private function debourses(array $ids): Collection
    {
        $debourses = Debourse::query()->whereIn('id', $ids)->with(['fournisseur.adresses', 'compteBancaire', 'lignes.facture'])
            ->orderBy('date_debourse')->orderBy('id')->get();
        if ($debourses->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['debourses' => __('Déboursé introuvable.')]);
        }

        return $debourses;
    }
}
