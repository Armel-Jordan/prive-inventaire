<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\ComptesClients;
use App\Support\FichierCsv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/** Comptes clients : analyse chronologique des sommes dues et relevé de compte d'un client (écran ou CSV). */
class CompteClientController extends Controller
{
    public function __construct(private ComptesClients $comptes) {}

    public function analyse(Request $request): JsonResponse|Response
    {
        $date = Carbon::parse($request->validate(['date' => ['nullable', 'date']])['date'] ?? today())->toDateString();
        $analyse = $this->comptes->analyse($date);
        if ($request->query('format') !== 'csv') {
            return response()->json($analyse);
        }

        $lignes = array_map(fn (array $c) => [$c['code'], $c['raison_sociale'], $c['nb_factures'], $c['retard_max'], (float) $c['total'],
            (float) $c['non_echu'], (float) $c['jours_1_30'], (float) $c['jours_31_60'], (float) $c['jours_61_90'], (float) $c['plus_90'],
            (float) $c['credits'], (float) $c['solde']], $analyse['clients']);
        $t = $analyse['totaux'];
        $lignes[] = ['', 'Totaux', '', '', (float) $t['total'], (float) $t['non_echu'], (float) $t['jours_1_30'], (float) $t['jours_31_60'], (float) $t['jours_61_90'], (float) $t['plus_90'],
            (float) $t['credits'], (float) $t['solde']];

        return FichierCsv::reponse("comptes-clients-{$date}.csv",
            ['code', 'client', 'factures', 'retard_max_jours', 'total', 'non_echu', '1_30_jours', '31_60_jours', '61_90_jours', 'plus_90_jours', 'credits', 'solde'], $lignes);
    }

    public function releve(Request $request, int $client): JsonResponse|Response
    {
        $dates = $request->validate(['du' => ['nullable', 'date'], 'au' => ['nullable', 'date', 'after_or_equal:du']]);
        $au = Carbon::parse($dates['au'] ?? today())->toDateString();
        $du = Carbon::parse($dates['du'] ?? Carbon::parse($au)->startOfYear())->toDateString();
        $releve = $this->comptes->releve(Client::findOrFail($client), $du, $au);
        if ($request->query('format') !== 'csv') {
            return response()->json($releve);
        }

        $lignes = [['', '', 'Solde d\'ouverture', 0.0, 0.0, (float) $releve['solde_ouverture']]];
        foreach ($releve['lignes'] as $l) {
            $lignes[] = [$l['date'], $l['piece'], $l['libelle'], (float) $l['debit'], (float) $l['credit'], (float) $l['solde']];
        }
        $lignes[] = ['', '', 'Solde final', (float) $releve['total_debit'], (float) $releve['total_credit'], (float) $releve['solde_final']];

        return FichierCsv::reponse("releve-{$releve['client']['code']}-{$du}-{$au}.csv", ['date', 'piece', 'libelle', 'debit', 'credit', 'solde'], $lignes);
    }
}
