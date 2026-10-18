<?php

namespace App\Services\Banque;

use App\Models\CompteBancaire;
use App\Models\CompteComptable;
use App\Models\Configuration;
use App\Models\TransactionBancaire;
use App\Models\TransactionBancaireLigne;
use App\Services\DevisesVente;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transactions bancaires sans facture (plan de parité fripv2, phase 4.6 : « Transaction bancaire ») :
 * - un dépôt (argent reçu) ou un retrait (argent sorti) se répartit sur des comptes de contrepartie : produits, charges
 *   (frais bancaires, intérêts, dépense payée par chèque ou prélèvement…) ; une ligne négative passe de l'autre côté ;
 * - un virement va d'un compte bancaire de l'entreprise vers un autre, dans la même devise ;
 * - les montants sont dans la devise du compte bancaire, au taux saisi (sinon celui du référentiel) ;
 * - une transaction s'annule (son écriture est contre-passée) ; elle se saisit de nouveau si elle était fausse.
 * La comptabilisation automatique (phase 4.3) la passe au journal du compte bancaire.
 */
class TransactionsBancaires
{
    public function relations(): array
    {
        return [
            'compteBancaire:id,nom,banque,devise_code,compte_comptable_id', 'destination:id,nom,banque,devise_code,compte_comptable_id',
            'lignes.compte:id,numero,libelle', 'auteur:id,nom', 'auteurAnnulation:id,nom',
        ];
    }

    /**
     * @param  array{compte_bancaire_id: int, date_transaction: string, type: string, compte_bancaire_destination_id?: ?int, reference?: ?string, libelle: string, montant?: mixed, taux_change?: mixed, notes?: ?string, lignes?: list<array{compte_comptable_id: int, libelle?: ?string, montant: mixed}>}  $donnees
     */
    public function enregistrer(array $donnees, int $tenantId, ?int $auteur): TransactionBancaire
    {
        $compte = CompteBancaire::query()->where('actif', true)->find($donnees['compte_bancaire_id']);
        if (! $compte) {
            throw ValidationException::withMessages(['compte_bancaire_id' => __('Choisissez un compte bancaire actif.')]);
        }
        $devise = app(DevisesVente::class)->pourDocument($compte->devise_code ?: null, $donnees['taux_change'] ?? null, null);
        $type = $donnees['type'];
        $destination = null;
        $lignes = collect();

        if ($type === TransactionBancaire::VIREMENT) {
            $destination = CompteBancaire::query()->where('actif', true)->find($donnees['compte_bancaire_destination_id'] ?? 0);
            if (! $destination || $destination->id === $compte->id) {
                throw ValidationException::withMessages(['compte_bancaire_destination_id' => __('Choisissez un autre compte bancaire actif, qui reçoit le virement.')]);
            }
            if (! DevisesVente::memeDevise($destination->devise_code ?: null, $compte->devise_code ?: null)) {
                throw ValidationException::withMessages(['compte_bancaire_destination_id' => __('Un virement se fait entre deux comptes de la même devise.')]);
            }
            $montant = round((float) ($donnees['montant'] ?? 0), 2);
            if ($montant <= 0) {
                throw ValidationException::withMessages(['montant' => __('Indiquez le montant viré.')]);
            }
        } else {
            $lignes = collect($donnees['lignes'] ?? [])
                ->map(fn (array $l) => ['compte_comptable_id' => (int) $l['compte_comptable_id'], 'libelle' => trim((string) ($l['libelle'] ?? '')) ?: null, 'montant' => round((float) $l['montant'], 2)])
                ->filter(fn (array $l) => $l['montant'] != 0)->values();
            if ($lignes->isEmpty()) {
                throw ValidationException::withMessages(['lignes' => __('Ajoutez au moins une ligne : un compte et un montant.')]);
            }
            $this->verifierComptes($lignes->pluck('compte_comptable_id')->all(), $compte);
            $montant = round($lignes->sum('montant'), 2);
            if ($montant <= 0) {
                throw ValidationException::withMessages(['lignes' => __('Le total de la transaction doit être positif.')]);
            }
            // Montant saisi à part : il doit égaler le total des lignes.
            if (filled($donnees['montant'] ?? null) && abs(round((float) $donnees['montant'], 2) - $montant) > 0.005) {
                throw ValidationException::withMessages(['montant' => __('Le montant de la transaction diffère du total des lignes (:total).', ['total' => number_format($montant, 2, ',', ' ')])]);
            }
        }

        return DB::transaction(function () use ($donnees, $tenantId, $auteur, $compte, $devise, $type, $destination, $lignes, $montant) {
            $transaction = TransactionBancaire::create([
                'tenant_id' => $tenantId,
                'numero' => $this->numero($tenantId),
                'compte_bancaire_id' => $compte->id,
                'date_transaction' => Carbon::parse($donnees['date_transaction'])->toDateString(),
                'type' => $type,
                'compte_bancaire_destination_id' => $destination?->id,
                'reference' => trim((string) ($donnees['reference'] ?? '')) ?: null,
                'libelle' => trim($donnees['libelle']),
                'devise' => $devise['devise'],
                'taux_change' => $devise['taux_change'],
                'montant' => $montant,
                'notes' => trim((string) ($donnees['notes'] ?? '')) ?: null,
                'statut' => TransactionBancaire::VALIDE,
                'enregistre_par' => $auteur,
            ]);
            foreach ($lignes as $ordre => $ligne) {
                TransactionBancaireLigne::create($ligne + ['transaction_bancaire_id' => $transaction->id, 'ordre' => $ordre]);
            }

            return $transaction->fresh($this->relations());
        });
    }

    /** Annule une transaction saisie par erreur : son écriture déjà passée au journal sera contre-passée. */
    public function annuler(TransactionBancaire $transaction, ?string $motif, ?int $auteur): TransactionBancaire
    {
        return DB::transaction(function () use ($transaction, $motif, $auteur) {
            $transaction = TransactionBancaire::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($transaction->statut === TransactionBancaire::ANNULE) {
                throw new DomainException(__('La transaction :numero est déjà annulée.', ['numero' => $transaction->numero]));
            }
            app(Rapprochements::class)->verifierDocument('transaction_bancaire', $transaction->id, __('La transaction :numero', ['numero' => $transaction->numero]));
            $transaction->update([
                'statut' => TransactionBancaire::ANNULE,
                'annule_le' => now(),
                'annule_par' => $auteur,
                'motif_annulation' => trim((string) $motif) ?: null,
            ]);

            return $transaction->load($this->relations());
        });
    }

    /**
     * Comptes de contrepartie : ils existent, sont actifs, de détail et ouverts à la saisie manuelle ; le compte du
     * compte bancaire lui-même n'en est pas un.
     *
     * @param  list<int>  $ids
     */
    private function verifierComptes(array $ids, CompteBancaire $compte): void
    {
        $comptes = CompteComptable::query()->whereIn('id', $ids)->get()->keyBy('id');
        foreach (array_values($ids) as $index => $id) {
            $c = $comptes->get($id);
            $erreur = match (true) {
                ! $c => __('compte introuvable'),
                ! $c->actif => __('le compte :numero est inactif', ['numero' => $c->numero]),
                ! $c->estImputable() => __("le compte :numero est un en-tête : choisissez l'un de ses sous-comptes", ['numero' => $c->numero]),
                ! $c->saisie_manuelle => __("le compte :numero n'accepte pas la saisie manuelle", ['numero' => $c->numero]),
                $c->id === $compte->compte_comptable_id => __('le compte :numero est celui du compte bancaire lui-même', ['numero' => $c->numero]),
                default => null,
            };
            if ($erreur) {
                throw ValidationException::withMessages(["lignes.{$index}.compte_comptable_id" => __('Ligne :rang : :probleme.', ['rang' => $index + 1, 'probleme' => $erreur])]);
            }
        }
    }

    private function numero(int $tenantId): string
    {
        $config = Configuration::pourEntite('transaction_bancaire', $tenantId);
        if (! $config?->auto_increment) {
            throw new DomainException(__('Numéro requis : activez la numérotation automatique des transactions bancaires (Configuration → Numérotation).'));
        }

        return Configuration::consommerNumero('transaction_bancaire', $tenantId);
    }
}
