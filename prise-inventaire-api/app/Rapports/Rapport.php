<?php

namespace App\Rapports;

use App\Support\TenantRule;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Rapport de la bibliothèque (plan de parité fripv2, phase 5.1) : une catégorie, le module dont il faut le droit de
 * voir, des paramètres (dates, choix, client…), des colonnes et des lignes. Les libellés sont donnés en français et en
 * anglais ; les montants sont dans la devise de l'entreprise (montants des documents × leur taux de change).
 */
abstract class Rapport
{
    /** Types de paramètres : date, entier, choix (options), client, fournisseur, categorie (de produits), representant. */
    public const TYPES_PARAMETRES = ['date', 'entier', 'choix', 'client', 'fournisseur', 'categorie', 'representant'];

    /** Types de colonnes : texte, date, entier, quantite, montant, pourcentage. */
    public const TYPES_COLONNES = ['texte', 'date', 'entier', 'quantite', 'montant', 'pourcentage'];

    /** Langue des textes produits dans les lignes (statuts…) : fr ou en. */
    public string $langue = 'fr';

    /** Un libellé dans la langue du rapport. */
    public function texte(array $libelle): string
    {
        return $libelle[$this->langue] ?? $libelle['fr'];
    }

    /** Code du rapport, dans son adresse. */
    abstract public function code(): string;

    /** Catégorie : ventes, clients, achats, inventaire, finance. */
    abstract public function categorie(): string;

    /** Module dont il faut le droit de voir. */
    abstract public function module(): string;

    /** @return array{fr: string, en: string} */
    abstract public function titre(): array;

    /** @return array{fr: string, en: string} */
    abstract public function description(): array;

    /**
     * @return array<string, array{type: string, libelle: array{fr: string, en: string}, defaut?: mixed, options?: array<string, array{fr: string, en: string}>, min?: int, max?: int}>
     */
    abstract public function parametres(): array;

    /**
     * @return array<string, array{libelle: array{fr: string, en: string}, type: string, total?: bool}>
     */
    abstract public function colonnes(array $valeurs): array;

    /** @return list<array<string, mixed>> */
    abstract public function lignes(array $valeurs): array;

    /** Période par défaut : du premier jour du mois à aujourd'hui. */
    protected function periode(): array
    {
        return [
            'du' => ['type' => 'date', 'libelle' => ['fr' => 'Du', 'en' => 'From'], 'defaut' => today()->startOfMonth()->toDateString()],
            'au' => ['type' => 'date', 'libelle' => ['fr' => 'Au', 'en' => 'To'], 'defaut' => today()->toDateString()],
        ];
    }

    /** Règles de validation des paramètres saisis. */
    public function regles(): array
    {
        $regles = [];
        foreach ($this->parametres() as $cle => $parametre) {
            $regles[$cle] = match ($parametre['type']) {
                'date' => ['nullable', 'date_format:Y-m-d'],
                'entier' => ['nullable', 'integer', 'min:'.($parametre['min'] ?? 0), 'max:'.($parametre['max'] ?? 100000)],
                'choix' => ['nullable', Rule::in(array_keys($parametre['options'] ?? []))],
                'client' => ['nullable', 'integer', TenantRule::exists('clients')],
                'fournisseur' => ['nullable', 'integer', TenantRule::exists('fournisseurs')],
                'categorie' => ['nullable', 'string', 'max:100'],
                'representant' => ['nullable', 'integer', TenantRule::exists('representants')],
            };
        }

        return $regles;
    }

    /** Valeurs des paramètres : celles saisies, sinon leur valeur par défaut ; une période inversée est remise dans l'ordre. */
    public function valeurs(array $saisies): array
    {
        $valeurs = [];
        foreach ($this->parametres() as $cle => $parametre) {
            $valeurs[$cle] = ($saisies[$cle] ?? null) !== null && $saisies[$cle] !== '' ? $saisies[$cle] : ($parametre['defaut'] ?? null);
            if ($parametre['type'] === 'entier' && $valeurs[$cle] !== null) {
                $valeurs[$cle] = (int) $valeurs[$cle];
            }
        }
        if (isset($valeurs['du'], $valeurs['au']) && $valeurs['du'] > $valeurs['au']) {
            [$valeurs['du'], $valeurs['au']] = [$valeurs['au'], $valeurs['du']];
        }

        return $valeurs;
    }

    /** Jours entre une date et aujourd'hui (âge d'une commande, retard). */
    protected function joursDepuis(mixed $date): ?int
    {
        return $date ? (int) Carbon::parse($date)->startOfDay()->diffInDays(today(), false) : null;
    }

    /** Arrondi des montants (devise de l'entreprise). */
    protected function argent(mixed $valeur): float
    {
        return round((float) $valeur, 2);
    }
}
