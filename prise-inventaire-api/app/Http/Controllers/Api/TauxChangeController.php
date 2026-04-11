<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TauxChangeController extends Controller
{
    public function getTaux(Request $request): JsonResponse
    {
        $from = strtoupper($request->get('from', 'EUR'));
        $to = strtoupper($request->get('to', 'EUR'));

        if ($from === $to) {
            return response()->json(['from' => $from, 'to' => $to, 'taux' => 1.0, 'date' => now()->toDateString()]);
        }

        $cacheKey = "taux_change_{$from}_{$to}_".now()->toDateString();

        $data = Cache::remember($cacheKey, 3600 * 6, function () use ($from, $to) {
            $response = Http::timeout(5)->get('https://api.frankfurter.app/latest', [
                'from' => $from,
                'to' => $to,
            ]);

            if ($response->successful()) {
                $body = $response->json();

                return [
                    'taux' => $body['rates'][$to] ?? 1.0,
                    'date' => $body['date'] ?? now()->toDateString(),
                ];
            }

            return null;
        });

        if (! $data) {
            return response()->json(['message' => 'Impossible de récupérer le taux de change.'], 503);
        }

        return response()->json([
            'from' => $from,
            'to' => $to,
            'taux' => $data['taux'],
            'date' => $data['date'],
        ]);
    }
}
