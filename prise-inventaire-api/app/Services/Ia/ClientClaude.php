<?php

namespace App\Services\Ia;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Appel de l'API Messages d'Anthropic (Claude) : consignes, outils et échanges en entrée ; la réponse (blocs de texte
 * et appels d'outils, jetons consommés) en sortie. Une erreur du service devient un message lisible.
 */
class ClientClaude
{
    public const VERSION_API = '2023-06-01';

    /** L'IA est-elle configurée sur ce serveur (clé de l'API) ? */
    public static function configure(): bool
    {
        return filled(config('ia.cle'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $consignes  blocs de texte du message système
     * @param  array<int, array<string, mixed>>  $outils
     * @param  array<int, array<string, mixed>>  $messages
     * @return array{content: list<array<string, mixed>>, stop_reason: ?string, usage: array<string, int>, model?: string}
     */
    public function message(array $consignes, array $outils, array $messages, int $jetonsMax = 4096): array
    {
        try {
            $reponse = Http::withHeaders(['x-api-key' => (string) config('ia.cle'), 'anthropic-version' => self::VERSION_API])
                ->acceptJson()
                ->timeout((int) config('ia.delai', 120))
                ->post((string) config('ia.url'), array_filter([
                    'model' => config('ia.modele'),
                    'max_tokens' => $jetonsMax,
                    'system' => $consignes,
                    // Sans outils (simple commentaire), la clé n'est pas envoyée.
                    'tools' => $outils ?: null,
                    'messages' => $messages,
                ], fn ($valeur) => $valeur !== null));
        } catch (ConnectionException) {
            throw new RuntimeException(__("Le service d'IA ne répond pas ; réessayez dans un moment."));
        }

        if ($reponse->failed()) {
            throw new RuntimeException(match (true) {
                in_array($reponse->status(), [401, 403], true) => __("La clé de l'API du service d'IA est refusée : vérifiez la configuration du serveur."),
                $reponse->status() === 429 => __("Trop de demandes au service d'IA ; réessayez dans une minute."),
                in_array($reponse->status(), [500, 502, 503, 529], true) => __("Le service d'IA est surchargé ; réessayez dans un moment."),
                default => __("Le service d'IA a refusé la demande : :detail", [
                    'detail' => mb_strimwidth((string) ($reponse->json('error.message') ?? $reponse->status()), 0, 200, '…'),
                ]),
            });
        }

        return $reponse->json() + ['content' => [], 'stop_reason' => null, 'usage' => []];
    }
}
