<?php

namespace App\Services;

use App\Models\ChampPersonnalise;
use App\Models\ChampPersonnaliseValeur;
use Illuminate\Validation\Rule;

/**
 * Valeurs des champs personnalisés : règles de saisie selon le type, conversion vers le stockage
 * (texte) et vers l'affichage, contrôle des doublons.
 */
class ValeursChampsPersonnalises
{
    /** Messages en français, nommés d'après le libellé du champ. */
    public const MESSAGES = [
        'required' => 'Le champ « :attribute » est obligatoire.',
        'string' => 'Le champ « :attribute » doit être un texte.',
        'max' => 'Le champ « :attribute » est trop long.',
        'numeric' => 'Le champ « :attribute » doit être un nombre.',
        'between' => 'Le champ « :attribute » est hors limites.',
        'date_format' => 'Le champ « :attribute » doit être une date valide.',
        'boolean' => 'Le champ « :attribute » doit valoir oui ou non.',
        'in' => 'Le choix « :input » n\'existe pas pour « :attribute ».',
        'array' => 'Le champ « :attribute » doit être une liste de choix.',
        'email' => 'Le champ « :attribute » doit être une adresse courriel valide.',
        'url' => 'Le champ « :attribute » doit être une adresse web (http ou https).',
        'regex' => 'Le champ « :attribute » doit être un numéro de téléphone.',
    ];

    /**
     * Règles de la valeur d'un champ (clé `$cle`), et de chaque choix pour une sélection multiple.
     *
     * @param  bool  $obligatoireApplique  false pour une valeur par défaut (jamais exigée)
     */
    public function regles(ChampPersonnalise $champ, string $cle, bool $obligatoireApplique = true): array
    {
        $presence = $obligatoireApplique && $champ->obligatoire && $champ->type !== 'case' ? 'required' : 'nullable';
        $options = $champ->options ?? [];

        $regles = [$cle => match ($champ->type) {
            'texte' => [$presence, 'string', 'max:255'],
            'texte_long' => [$presence, 'string', 'max:5000'],
            'nombre' => [$presence, 'numeric', 'between:-999999999999,999999999999'],
            'date' => [$presence, 'date_format:Y-m-d'],
            'case' => ['nullable', 'boolean'],
            'liste' => [$presence, 'string', Rule::in($options)],
            'liste_multiple' => [$presence, 'array'],
            'courriel' => [$presence, 'email', 'max:255'],
            'url' => [$presence, 'url:http,https', 'max:500'],
            'telephone' => [$presence, 'string', 'max:30', 'regex:/^[0-9+().\s\/-]+$/'],
        }];

        if ($champ->type === 'liste_multiple') {
            $regles[$cle.'.*'] = ['string', Rule::in($options)];
        }

        return $regles;
    }

    /** Valeur enregistrée (texte), ou null si elle est vide. */
    public function versStockage(ChampPersonnalise $champ, mixed $valeur): ?string
    {
        if ($valeur === null || $valeur === '' || $valeur === []) {
            return null;
        }

        return match ($champ->type) {
            'case' => filter_var($valeur, FILTER_VALIDATE_BOOL) ? '1' : '0',
            'liste_multiple' => json_encode(array_values(array_unique(array_map('strval', (array) $valeur))), JSON_UNESCAPED_UNICODE),
            // Point décimal, sans zéros inutiles : « 12.5 », « 1200 ».
            'nombre' => rtrim(rtrim(number_format((float) $valeur, $champ->decimales ?? 6, '.', ''), '0'), '.'),
            default => trim((string) $valeur),
        };
    }

    /** Valeur pour l'écran : booléen, liste, nombre ou texte. */
    public function versAffichage(ChampPersonnalise $champ, ?string $stockee): mixed
    {
        if ($stockee === null) {
            return match ($champ->type) {
                'case' => false,
                'liste_multiple' => [],
                default => null,
            };
        }

        return match ($champ->type) {
            'case' => $stockee === '1',
            'liste_multiple' => json_decode($stockee, true) ?: [],
            'nombre' => $stockee + 0,
            default => $stockee,
        };
    }

    /** Une autre fiche porte-t-elle déjà cette valeur (casse ignorée) ? */
    public function estUnDoublon(ChampPersonnalise $champ, string $stockee, int $entiteId): bool
    {
        return ChampPersonnaliseValeur::query()
            ->where('champ_personnalise_id', $champ->id)
            ->where('entite_id', '!=', $entiteId)
            ->whereRaw('LOWER(valeur) = ?', [mb_strtolower($stockee)])
            ->exists();
    }
}
