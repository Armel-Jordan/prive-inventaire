<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Devise;
use App\Models\TenantParametres;
use Illuminate\Validation\ValidationException;

/**
 * Devises des documents de vente (plan de parité fripv2, phase 1.5) :
 * - un document est dans la devise demandée, sinon dans celle de son client ; la devise de l'entreprise s'écrit
 *   null, au taux 1 ;
 * - son taux de change (valeur d'une unité de sa devise dans la devise de l'entreprise) est celui saisi, sinon celui
 *   du référentiel des devises ; sans l'un ni l'autre, il est demandé (422) plutôt que pris à 1 en silence.
 */
class DevisesVente
{
    /** Code de la devise de l'entreprise (paramètres). */
    public function reference(): string
    {
        return strtoupper((string) TenantParametres::query()->value('devise_code')) ?: 'EUR';
    }

    /**
     * Devise et taux d'un nouveau document.
     *
     * @return array{devise: ?string, taux_change: float}
     */
    public function pourDocument(?string $devise, mixed $taux, ?Client $client): array
    {
        $code = strtoupper(trim((string) ($devise ?: $client?->devise ?: '')));
        if ($code === '' || $code === $this->reference()) {
            return ['devise' => null, 'taux_change' => 1.0];
        }
        if (is_numeric($taux) && (float) $taux > 0) {
            return ['devise' => $code, 'taux_change' => round((float) $taux, 8)];
        }
        $referentiel = Devise::where('code', $code)->where('actif', true)->value('taux');
        if (is_numeric($referentiel) && (float) $referentiel > 0) {
            return ['devise' => $code, 'taux_change' => round((float) $referentiel, 8)];
        }

        throw ValidationException::withMessages(['taux_change' => "Indiquez le taux de change {$code} → {$this->reference()}."]);
    }

    /** Règles de validation des champs devise et taux d'un document. */
    public static function regles(): array
    {
        return [
            'devise' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'taux_change' => ['nullable', 'numeric', 'gt:0'],
        ];
    }

    /** Symbole d'affichage d'une devise : celui du référentiel, sinon son code ; null : la devise de l'entreprise. */
    public function symbole(?string $devise): string
    {
        $parametres = TenantParametres::query()->first(['devise_code', 'devise_symbole']);
        if (! $devise || strtoupper($devise) === strtoupper((string) $parametres?->devise_code)) {
            return (string) ($parametres?->devise_symbole ?: $parametres?->devise_code ?: '€');
        }

        return (string) (Devise::where('code', strtoupper($devise))->value('symbole') ?: strtoupper($devise));
    }

    /** Deux documents dans la même devise (null : celle de l'entreprise) ? */
    public static function memeDevise(?string $a, ?string $b): bool
    {
        return strtoupper((string) $a) === strtoupper((string) $b);
    }
}
