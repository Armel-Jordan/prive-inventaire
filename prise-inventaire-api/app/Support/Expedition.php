<?php

namespace App\Support;

use App\Models\Transporteur;
use Illuminate\Database\Eloquent\Model;

/**
 * Expédition d'un document (plan de parité fripv2, phase 1.11b) : son transporteur, son numéro de suivi et le lien de
 * suivi du transporteur, {numero} remplacé par le numéro. Sert aux PDF, aux variables de courriel et à l'espace client.
 */
class Expedition
{
    /**
     * @return array{transporteur: ?string, numero_suivi: ?string, lien_suivi: ?string}|null null si le document n'a ni transporteur ni numéro
     */
    public static function de(?Model $document): ?array
    {
        if (! $document) {
            return null;
        }
        $numero = filled($document->getAttribute('numero_suivi')) ? (string) $document->getAttribute('numero_suivi') : null;
        $transporteur = $document->getAttribute('transporteur_id')
            ? Transporteur::withTrashed()->whereKey($document->getAttribute('transporteur_id'))->first(['id', 'nom', 'url_suivi'])
            : null;
        if (! $transporteur && ! $numero) {
            return null;
        }

        return [
            'transporteur' => $transporteur?->nom,
            'numero_suivi' => $numero,
            'lien_suivi' => self::lien($transporteur?->url_suivi, $numero),
        ];
    }

    /** Lien de suivi : celui du transporteur, {numero} remplacé ; null s'il manque le lien ou le numéro. */
    public static function lien(?string $urlSuivi, ?string $numero): ?string
    {
        if (blank($urlSuivi) || blank($numero)) {
            return null;
        }

        return str_contains($urlSuivi, '{numero}') ? str_replace('{numero}', rawurlencode($numero), $urlSuivi) : $urlSuivi;
    }
}
