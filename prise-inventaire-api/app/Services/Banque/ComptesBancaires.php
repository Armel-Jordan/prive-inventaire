<?php

namespace App\Services\Banque;

use App\Models\CompteBancaire;
use App\Models\CompteComptable;
use App\Models\JournalComptable;
use App\Services\Comptabilite\Soldes;
use DomainException;
use Illuminate\Validation\ValidationException;

/**
 * Comptes bancaires de l'entreprise côté comptabilité (plan de parité fripv2, phase 4.6) : chacun est relié à son compte
 * comptable de trésorerie (un compte par compte bancaire) et à son journal. Son solde et ses mouvements sont ceux de ce
 * compte comptable : encaissements, déboursés, paiements, transactions bancaires et écritures saisies, quelle qu'en soit
 * l'origine, une fois passés au journal.
 */
class ComptesBancaires
{
    public function __construct(private readonly Soldes $soldes) {}

    /** Comptes bancaires, avec leur compte comptable, leur journal et leur solde comptable à une date. */
    public function liste(string $date): array
    {
        $comptes = CompteBancaire::query()->with(['compteComptable:id,numero,libelle', 'journal:id,code,libelle'])
            ->orderByDesc('actif')->orderBy('nom')->get();
        $soldes = $this->soldes->mouvements(null, $date, $comptes->pluck('compte_comptable_id')->filter()->values()->all());

        return $comptes->map(fn (CompteBancaire $c) => $c->only([
            'id', 'nom', 'banque', 'numero_compte', 'iban', 'devise_code', 'par_defaut', 'actif', 'compte_comptable_id', 'journal_id', 'prochain_cheque',
        ]) + [
            'compte_comptable' => $c->compteComptable?->only(['id', 'numero', 'libelle']),
            'journal' => $c->journal?->only(['id', 'code', 'libelle']),
            'solde' => $c->compte_comptable_id && isset($soldes[$c->compte_comptable_id])
                ? round($soldes[$c->compte_comptable_id]->debit - $soldes[$c->compte_comptable_id]->credit, 2) : ($c->compte_comptable_id ? 0.0 : null),
        ])->all();
    }

    /**
     * Relie un compte bancaire à son compte comptable (de trésorerie, de détail, actif, pas déjà relié à un autre compte
     * bancaire) et à son journal (vide : le journal de banque des réglages de comptabilisation).
     *
     * @param  array{compte_comptable_id?: ?int, journal_id?: ?int}  $donnees
     */
    public function configurer(CompteBancaire $compte, array $donnees): CompteBancaire
    {
        $compteComptable = null;
        if (! empty($donnees['compte_comptable_id'])) {
            $compteComptable = CompteComptable::query()->find($donnees['compte_comptable_id']);
            if (! $compteComptable || $compteComptable->type !== 'tresorerie' || ! $compteComptable->estImputable() || ! $compteComptable->actif) {
                throw ValidationException::withMessages(['compte_comptable_id' => 'Choisissez un compte de trésorerie actif, de détail (pas un en-tête).']);
            }
            $autre = CompteBancaire::query()->where('compte_comptable_id', $compteComptable->id)->whereKeyNot($compte->id)->value('nom');
            if ($autre) {
                throw ValidationException::withMessages(['compte_comptable_id' => "Le compte {$compteComptable->numero} est déjà celui du compte bancaire « {$autre} » : chaque compte bancaire a le sien."]);
            }
        }
        if (! empty($donnees['journal_id']) && ! JournalComptable::query()->where('actif', true)->whereKey($donnees['journal_id'])->exists()) {
            throw ValidationException::withMessages(['journal_id' => 'Choisissez un journal actif.']);
        }
        // Seuls les réglages envoyés changent : le compte comptable, le journal, le prochain numéro de chèque.
        $compte->update(array_intersect_key([
            'compte_comptable_id' => $compteComptable?->id,
            'journal_id' => $donnees['journal_id'] ?? null,
            'prochain_cheque' => $donnees['prochain_cheque'] ?? null,
        ], $donnees));

        return $compte->load(['compteComptable:id,numero,libelle', 'journal:id,code,libelle']);
    }

    /** Mouvements d'un compte bancaire entre deux dates : les lignes de son compte comptable, avec le solde progressif. */
    public function mouvements(CompteBancaire $compte, string $du, string $au, ?string $recherche = null): array
    {
        $compte->loadMissing('compteComptable');
        if (! $compte->compteComptable) {
            throw new DomainException("Reliez d'abord le compte bancaire « {$compte->nom} » à son compte comptable.");
        }

        return ['compte_bancaire' => $compte->only(['id', 'nom', 'banque', 'devise_code'])] + $this->soldes->grandLivre($compte->compteComptable, $du, $au, $recherche);
    }
}
