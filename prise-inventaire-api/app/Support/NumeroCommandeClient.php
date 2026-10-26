<?php

namespace App\Support;

use App\Models\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Numéro de commande du client (plan de parité fripv2, socle S4.1) : la référence que le client donne à sa commande
 * (son bon de commande), 30 caractères, sur la commande, la facture et le bon de livraison, reprise de l'une à l'autre.
 * Une fiche client peut l'exiger (« Exige un numéro de commande ») : il est alors demandé à la saisie d'une commande ou
 * d'une facture, pas d'un devis ni d'une note de crédit, comme dans fripv2.
 */
class NumeroCommandeClient
{
    /**
     * Règles du champ : exigé à la création si le client l'exige ; transmis vide à la modification, refusé.
     *
     * @param  Model|null  $document  le document modifié (null à la création)
     */
    public static function regles(?Model $document = null): array
    {
        return [
            'numero_commande_client' => ['nullable', 'string', 'max:30', Rule::requiredIf(fn () => self::exige(request()->input('client_id', $document?->getAttribute('client_id')))
                && (! $document || request()->exists('numero_commande_client')))],
        ];
    }

    public static function exige(mixed $clientId): bool
    {
        return $clientId && (bool) Client::query()->whereKey($clientId)->value('commande_client_obligatoire');
    }

    /** Valeur à enregistrer : sans espaces autour ; vide, aucune. */
    public static function valeur(mixed $numero): ?string
    {
        $numero = trim((string) $numero);

        return $numero === '' ? null : $numero;
    }
}
