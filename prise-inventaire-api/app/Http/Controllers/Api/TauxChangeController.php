<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Devise;
use App\Models\TenantParametres;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TauxChangeController extends Controller
{
    /**
     * Taux pour convertir 1 unité de `from` en `to`. Sources, dans l'ordre : les taux saisis par l'entreprise
     * dans son référentiel des devises, puis le cours du jour (Frankfurter). Sans l'une ni l'autre, réponse 404 :
     * l'écran demande alors le taux au lieu d'enregistrer en silence une commande au taux 1. (Un 404 et non un 503 :
     * l'absence de taux n'est pas une panne passagère, et le navigateur ne relance pas la demande trois fois.)
     */
    public function getTaux(Request $request): JsonResponse
    {
        $from = strtoupper((string) $request->get('from', 'EUR'));
        $to = strtoupper((string) $request->get('to', 'EUR'));

        if (! preg_match('/^[A-Z]{3}$/', $from) || ! preg_match('/^[A-Z]{3}$/', $to)) {
            return response()->json(['message' => __('Code de devise invalide : trois lettres attendues (ex. USD).')], 422);
        }

        if ($from === $to) {
            return response()->json(['from' => $from, 'to' => $to, 'taux' => 1.0, 'date' => now()->toDateString(), 'source' => 'identique']);
        }

        $tauxEntreprise = $this->tauxEntreprise($from, $to);
        if ($tauxEntreprise !== null) {
            return response()->json([
                'from' => $from, 'to' => $to, 'taux' => $tauxEntreprise, 'date' => now()->toDateString(), 'source' => 'entreprise',
            ]);
        }

        // Un échec (devise inconnue du service, service injoignable) est retenu dix minutes : sans cela, chaque
        // ouverture du formulaire attendait de nouveau le délai de connexion.
        $cle = "taux_change_{$from}_{$to}_".now()->toDateString();
        $data = Cache::get($cle);
        if ($data === null) {
            $data = $this->coursDuJour($from, $to) ?? false;
            Cache::put($cle, $data, $data === false ? 600 : 3600 * 6);
        }

        if (! $data) {
            return response()->json([
                'message' => __('Taux de change :from → :to introuvable : saisissez-le, ou renseignez la devise dans le référentiel des devises.', [
                    'from' => $from, 'to' => $to,
                ]),
            ], 404);
        }

        return response()->json(['from' => $from, 'to' => $to, 'taux' => $data['taux'], 'date' => $data['date'], 'source' => 'frankfurter']);
    }

    /** Cours du jour publié par Frankfurter, ou null (devise inconnue du service, service injoignable). */
    private function coursDuJour(string $from, string $to): ?array
    {
        try {
            $response = Http::timeout(5)->get('https://api.frankfurter.app/latest', ['from' => $from, 'to' => $to]);
        } catch (ConnectionException) {
            return null;
        }
        $taux = $response->successful() ? $response->json("rates.{$to}") : null;

        // Pas de taux par défaut à 1, qui fausserait tous les montants convertis.
        return is_numeric($taux) && $taux > 0
            ? ['taux' => (float) $taux, 'date' => $response->json('date') ?? now()->toDateString()]
            : null;
    }

    /**
     * Taux tiré du référentiel des devises de l'entreprise, dont chaque taux exprime une unité de la devise dans
     * la devise de référence (paramètres de l'entreprise). Null si l'une des devises n'y a pas de taux.
     */
    private function tauxEntreprise(string $from, string $to): ?float
    {
        $reference = strtoupper((string) TenantParametres::query()->value('devise_code'));
        if ($reference === '') {
            return null;
        }

        $valeur = function (string $code) use ($reference): ?float {
            if ($code === $reference) {
                return 1.0;
            }
            $taux = Devise::query()->where('code', $code)->where('actif', true)->value('taux');

            return is_numeric($taux) && (float) $taux > 0 ? (float) $taux : null;
        };

        $source = $valeur($from);
        $cible = $valeur($to);

        return $source !== null && $cible !== null ? round($source / $cible, 8) : null;
    }
}
