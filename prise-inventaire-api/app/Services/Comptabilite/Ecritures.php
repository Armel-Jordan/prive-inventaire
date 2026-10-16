<?php

namespace App\Services\Comptabilite;

use App\Models\CompteComptable;
use App\Models\EcritureComptable;
use App\Models\ExerciceComptable;
use App\Models\JournalComptable;
use App\Models\PeriodeComptable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Écritures en partie double : contrôles, numérotation, modification, contre-passation, soldes d'ouverture.
 * Une écriture se passe dans une période ouverte d'un exercice ouvert, sur des comptes actifs qui ne sont pas des
 * en-têtes ; chaque ligne porte un débit ou un crédit, et les deux totaux sont égaux.
 */
class Ecritures
{
    /** Sources modifiables depuis le journal (les autres le sont depuis leur écran). */
    private const MODIFIABLES = [EcritureComptable::MANUELLE, EcritureComptable::RECURRENTE];

    public function __construct(private Exercices $exercices) {}

    /**
     * Crée ou remplace une écriture. $donnees : journal_id, date, libelle, reference, lignes (compte_id, libelle,
     * debit, credit). La saisie manuelle des comptes n'est exigée que pour une écriture manuelle ou récurrente.
     * Une écriture système (clôture) peut tomber dans une période fermée et solder un compte désactivé.
     *
     * @throws ValidationException
     */
    public function enregistrer(array $donnees, string $source, ?EcritureComptable $ecriture = null, ?int $auteur = null, ?int $contrepassationDe = null, bool $systeme = false): EcritureComptable
    {
        $date = Carbon::parse($donnees['date'])->startOfDay();
        $periode = $this->periodeOuverte($date, $systeme);
        $journal = JournalComptable::findOrFail($donnees['journal_id']);
        if (! $journal->actif) {
            throw ValidationException::withMessages(['journal_id' => "Le journal {$journal->code} est désactivé."]);
        }
        $lignes = $this->controlerLignes($donnees['lignes'], in_array($source, self::MODIFIABLES, true) && ! $contrepassationDe, $systeme);
        $total = array_sum(array_column($lignes, 'debit'));

        return DB::transaction(function () use ($donnees, $source, $ecriture, $auteur, $contrepassationDe, $date, $periode, $journal, $lignes, $total) {
            $exercice = $periode->exercice;
            $renumeroter = ! $ecriture || $ecriture->journal_id !== $journal->id || ! str_starts_with($ecriture->numero, $this->prefixe($journal, $exercice));
            $valeurs = [
                'journal_id' => $journal->id,
                'exercice_id' => $exercice->id,
                'periode_id' => $periode->id,
                'date' => $date->toDateString(),
                'libelle' => trim($donnees['libelle']),
                'reference' => trim((string) ($donnees['reference'] ?? '')) ?: null,
                'total' => $total,
            ];
            if ($renumeroter) {
                $valeurs['numero'] = $this->numero($journal, $exercice);
            }
            if ($ecriture) {
                $ecriture->update($valeurs);
                $ecriture->lignes()->delete();
            } else {
                $ecriture = EcritureComptable::create($valeurs + [
                    'source' => $source,
                    'contrepassation_de' => $contrepassationDe,
                    'creee_par' => $auteur,
                ]);
            }
            foreach ($lignes as $ordre => $ligne) {
                $ecriture->lignes()->create($ligne + ['ordre' => $ordre + 1]);
            }

            return $ecriture->load('lignes.compte:id,numero,libelle', 'journal:id,code,libelle');
        });
    }

    /** Raison pour laquelle une écriture ne peut plus être modifiée ni supprimée depuis le journal, null sinon. */
    public function refusModification(EcritureComptable $ecriture): ?string
    {
        if (! in_array($ecriture->source, self::MODIFIABLES, true)) {
            return match ($ecriture->source) {
                EcritureComptable::OUVERTURE => 'Les soldes d\'ouverture se modifient depuis leur écran.',
                EcritureComptable::CLOTURE => 'Une écriture de clôture s\'annule en rouvrant son exercice.',
                default => 'Cette écriture est générée par l\'application : contre-passez-la si elle doit être corrigée.',
            };
        }
        $ecriture->loadMissing('periode.exercice');
        if ($ecriture->periode->exercice->estCloture() || $ecriture->periode->fermee) {
            return 'La période de cette écriture est fermée : contre-passez-la plutôt que de la modifier.';
        }

        return null;
    }

    /** Écriture inverse (débits et crédits échangés), datée dans une période ouverte, qui annule l'originale. */
    public function contrePasser(EcritureComptable $ecriture, Carbon $date, ?int $auteur): EcritureComptable
    {
        if ($ecriture->source === EcritureComptable::CLOTURE) {
            throw ValidationException::withMessages(['date' => 'Une écriture de clôture s\'annule en rouvrant son exercice.']);
        }
        $deja = EcritureComptable::where('contrepassation_de', $ecriture->id)->value('numero');
        if ($deja) {
            throw ValidationException::withMessages(['date' => "Cette écriture a déjà été contre-passée par {$deja}."]);
        }
        $ecriture->loadMissing('lignes');

        return $this->enregistrer([
            'journal_id' => $ecriture->journal_id,
            'date' => $date->toDateString(),
            'libelle' => mb_substr("Contre-passation de {$ecriture->numero} : {$ecriture->libelle}", 0, 255),
            'reference' => $ecriture->numero,
            'lignes' => $ecriture->lignes->map(fn ($ligne) => [
                'compte_id' => $ligne->compte_id,
                'libelle' => $ligne->libelle,
                'debit' => $ligne->credit,
                'credit' => $ligne->debit,
            ])->all(),
        ], EcritureComptable::MANUELLE, null, $auteur, $ecriture->id);
    }

    /** Premier exercice (les suivants reprennent les soldes de clôture : pas de soldes d'ouverture à saisir). */
    public function exerciceOuverture(): ?ExerciceComptable
    {
        return ExerciceComptable::orderBy('date_debut')->first();
    }

    /**
     * Remplace l'écriture des soldes d'ouverture du premier exercice (journal des à-nouveaux, datée du premier
     * jour). Seuls les comptes de bilan y figurent ; des soldes tous nuls suppriment l'écriture.
     *
     * @param  list<array{compte_id: int, debit: mixed, credit: mixed}>  $lignes
     *
     * @throws ValidationException
     */
    public function enregistrerOuverture(array $lignes, ?int $auteur): ?EcritureComptable
    {
        $exercice = $this->exerciceOuverture()
            ?? throw ValidationException::withMessages(['lignes' => 'Créez d\'abord le premier exercice comptable.']);
        $lignes = array_values(array_filter($lignes, fn ($l) => round((float) ($l['debit'] ?? 0), 2) != 0 || round((float) ($l['credit'] ?? 0), 2) != 0));
        $comptes = CompteComptable::whereIn('id', array_column($lignes, 'compte_id'))->get()->keyBy('id');
        foreach ($lignes as $index => $ligne) {
            $compte = $comptes[$ligne['compte_id']] ?? null;
            if ($compte && in_array($compte->nature, ['produit', 'charge'], true)) {
                throw ValidationException::withMessages(["lignes.{$index}.compte_id" => "Le compte {$compte->numero} est un compte de résultat : les soldes d'ouverture ne portent que sur les comptes de bilan."]);
            }
        }
        $existante = EcritureComptable::where('source', EcritureComptable::OUVERTURE)->where('exercice_id', $exercice->id)->first();
        if ($existante && $this->periodeFermee($existante)) {
            throw ValidationException::withMessages(['lignes' => 'La première période est fermée : les soldes d\'ouverture ne peuvent plus changer.']);
        }
        if (! $lignes) {
            $existante?->delete();

            return null;
        }

        return $this->enregistrer([
            'journal_id' => JournalComptable::duType('ouverture')->id,
            'date' => $exercice->date_debut->toDateString(),
            'libelle' => 'Soldes d\'ouverture',
            'lignes' => $lignes,
        ], EcritureComptable::OUVERTURE, $existante, $auteur);
    }

    /**
     * Lignes contrôlées et arrondies au centime : un débit ou un crédit par ligne, comptes actifs imputables
     * (et ouverts à la saisie manuelle si demandé), totaux égaux.
     *
     * @return list<array{compte_id: int, libelle: ?string, debit: float, credit: float}>
     *
     * @throws ValidationException
     */
    private function controlerLignes(array $lignes, bool $saisieManuelle, bool $systeme = false): array
    {
        $comptes = CompteComptable::whereIn('id', array_column($lignes, 'compte_id'))->get()->keyBy('id');
        $erreurs = [];
        $propres = [];
        foreach (array_values($lignes) as $index => $ligne) {
            $rang = $index + 1;
            $debit = round((float) ($ligne['debit'] ?? 0), 2);
            $credit = round((float) ($ligne['credit'] ?? 0), 2);
            $compte = $comptes[$ligne['compte_id']] ?? null;
            $erreur = match (true) {
                ! $compte => 'compte introuvable',
                ! $compte->actif && ! $systeme => "le compte {$compte->numero} est inactif",
                ! $compte->estImputable() => "le compte {$compte->numero} est un en-tête : choisissez l'un de ses sous-comptes",
                $saisieManuelle && ! $compte->saisie_manuelle => "le compte {$compte->numero} n'accepte pas la saisie manuelle",
                $debit < 0 || $credit < 0 => 'montant négatif',
                $debit > 0 && $credit > 0 => 'un débit ou un crédit, pas les deux',
                $debit == 0 && $credit == 0 => 'saisissez un débit ou un crédit',
                default => null,
            };
            if ($erreur) {
                $erreurs["lignes.{$index}"] = "Ligne {$rang} : {$erreur}.";

                continue;
            }
            $propres[] = [
                'compte_id' => $compte->id,
                'libelle' => trim((string) ($ligne['libelle'] ?? '')) ?: null,
                'debit' => $debit,
                'credit' => $credit,
            ];
        }
        if ($erreurs) {
            throw ValidationException::withMessages($erreurs);
        }
        $debits = (int) round(array_sum(array_column($propres, 'debit')) * 100);
        $credits = (int) round(array_sum(array_column($propres, 'credit')) * 100);
        if ($debits !== $credits) {
            $ecart = $this->montant(abs($debits - $credits) / 100);
            throw ValidationException::withMessages(['lignes' => "L'écriture n'est pas équilibrée : débit {$this->montant($debits / 100)}, crédit {$this->montant($credits / 100)} (écart {$ecart})."]);
        }

        return $propres;
    }

    /** Période ouverte d'un exercice ouvert qui contient la date. */
    private function periodeOuverte(Carbon $date, bool $systeme = false): PeriodeComptable
    {
        $periode = $this->exercices->periodeDu($date)?->load('exercice');
        if (! $periode) {
            throw ValidationException::withMessages(['date' => "Aucun exercice ne couvre le {$date->format('d/m/Y')} : créez-le dans Exercices comptables."]);
        }
        if ($periode->exercice->estCloture()) {
            throw ValidationException::withMessages(['date' => "L'exercice {$periode->exercice->libelle} est clôturé."]);
        }
        if ($periode->fermee && ! $systeme) {
            throw ValidationException::withMessages(['date' => "La période du {$periode->date_debut->format('d/m/Y')} au {$periode->date_fin->format('d/m/Y')} est fermée."]);
        }

        return $periode;
    }

    private function periodeFermee(EcritureComptable $ecriture): bool
    {
        $ecriture->loadMissing('periode.exercice');

        return $ecriture->periode->fermee || $ecriture->periode->exercice->estCloture();
    }

    private function prefixe(JournalComptable $journal, ExerciceComptable $exercice): string
    {
        return "{$journal->code}-{$exercice->date_debut->year}-";
    }

    /** Numéro suivant du journal pour l'année de début de l'exercice (OD-2026-00001), sans réutiliser un rang. */
    private function numero(JournalComptable $journal, ExerciceComptable $exercice): string
    {
        $prefixe = $this->prefixe($journal, $exercice);
        $dernier = EcritureComptable::where('numero', 'like', $prefixe.'%')->lockForUpdate()->orderByDesc('numero')->value('numero');
        $rang = $dernier ? ((int) substr($dernier, strlen($prefixe))) + 1 : 1;

        return $prefixe.str_pad((string) $rang, 5, '0', STR_PAD_LEFT);
    }

    private function montant(float $valeur): string
    {
        return number_format($valeur, 2, ',', ' ');
    }
}
