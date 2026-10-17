@php
    $nombre = fn ($valeur) => (float) $valeur == 0 ? '' : number_format((float) $valeur, 2, ',', ' ');
    $montant = fn ($valeur) => number_format((float) $valeur, 2, ',', ' ');
    $date = fn ($valeur) => \Illuminate\Support\Carbon::parse($valeur)->format('d/m/Y');
    $detail = $niveau === 'detail';
    $parJour = $niveau === 'jour';
    $roles = ['collectee' => 'collectée', 'deductible' => 'déductible'];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Rapport des taxes du {{ $date($du) }} au {{ $date($au) }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 8.5px; line-height: 1.35; color: #333; }
        .container { padding: 16px; }
        .header { display: table; width: 100%; margin-bottom: 14px; }
        .header-left, .header-right { display: table-cell; vertical-align: top; width: 50%; }
        .company-name { font-size: 16px; font-weight: bold; color: #0d9488; margin-bottom: 4px; }
        .company-info { font-size: 8px; color: #666; }
        .document-title { text-align: right; }
        .document-title h1 { font-size: 16px; color: #1f2937; margin-bottom: 4px; }
        .sous-titre { font-size: 10px; color: #0d9488; font-weight: bold; }
        h2 { font-size: 10px; color: #0d9488; margin: 12px 0 5px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
        th { background-color: #0d9488; color: white; padding: 4px; text-align: left; font-size: 7.5px; text-transform: uppercase; }
        th.taxe { text-align: center; border-left: 1px solid #fff; }
        td { padding: 3px 4px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .right { text-align: right; }
        .total td { font-weight: bold; border-top: 1px solid #9ca3af; }
        .sommaire { width: 60%; }
        .note { margin-top: 6px; font-size: 8px; color: #6b7280; font-style: italic; }
        .vide { color: #9ca3af; font-style: italic; padding: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-left">
                <div class="company-name">{{ $entreprise['nom'] }}</div>
                <div class="company-info">Taxes collectées sur les ventes, déductibles sur les achats</div>
            </div>
            <div class="header-right document-title">
                <h1>RAPPORT DES TAXES</h1>
                <div class="sous-titre">Du {{ $date($du) }} au {{ $date($au) }} — {{ $niveaux[$niveau] }}</div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    @if($detail)
                        <th rowspan="2">Date</th><th rowspan="2">Type</th><th rowspan="2">N°</th><th rowspan="2">Tiers</th>
                    @elseif($parJour)
                        <th rowspan="2">Date</th>
                    @endif
                    <th rowspan="2" class="right">Ventes HT</th>
                    <th rowspan="2" class="right">Achats HT</th>
                    @foreach($taxes as $taxe)
                        <th colspan="2" class="taxe">{{ $taxe['nom'] }}</th>
                    @endforeach
                </tr>
                <tr>
                    @foreach($taxes as $taxe)
                        <th class="right taxe">Collectée</th><th class="right">Déductible</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($lignes as $ligne)
                    <tr>
                        @if($detail)
                            <td>{{ $date($ligne['date']) }}</td>
                            <td>{{ $types[$ligne['type']] }}</td>
                            <td>{{ $ligne['numero'] }}@if($ligne['reference'])<br>{{ $ligne['reference'] }}@endif</td>
                            <td>{{ $ligne['tiers'] }}</td>
                        @elseif($parJour)
                            <td>{{ $date($ligne['date']) }}</td>
                        @endif
                        <td class="right">{{ $nombre($ligne['ventes_ht']) }}</td>
                        <td class="right">{{ $nombre($ligne['achats_ht']) }}</td>
                        @foreach($taxes as $taxe)
                            <td class="right">{{ $nombre($ligne['collectee'][$taxe['cle']] ?? 0) }}</td>
                            <td class="right">{{ $nombre($ligne['deductible'][$taxe['cle']] ?? 0) }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ ($detail ? 6 : ($parJour ? 3 : 2)) + 2 * count($taxes) }}" class="vide">Aucune vente ni aucun achat sur la période.</td></tr>
                @endforelse
                @if($niveau !== 'periode' && count($lignes))
                    <tr class="total">
                        <td colspan="{{ $detail ? 4 : 1 }}">Total</td>
                        <td class="right">{{ $montant($totaux['ventes_ht']) }}</td>
                        <td class="right">{{ $montant($totaux['achats_ht']) }}</td>
                        @foreach($taxes as $taxe)
                            <td class="right">{{ $montant($totaux['collectee'][$taxe['cle']] ?? 0) }}</td>
                            <td class="right">{{ $montant($totaux['deductible'][$taxe['cle']] ?? 0) }}</td>
                        @endforeach
                    </tr>
                @endif
            </tbody>
        </table>
        @if($tronque)
            <p class="note">Détail limité aux {{ \App\Services\Comptabilite\RapportTaxes::MAX_DETAIL }} premiers documents ; les totaux portent sur toute la période.</p>
        @endif

        <h2>Sommaire par taxe</h2>
        <table class="sommaire">
            <thead><tr><th>Taxe</th><th class="right">Collectée</th><th class="right">Déductible</th><th class="right">Différence</th></tr></thead>
            <tbody>
                @foreach($sommaire as $taxe)
                    <tr><td>{{ $taxe['nom'] }}</td><td class="right">{{ $montant($taxe['collectee']) }}</td><td class="right">{{ $montant($taxe['deductible']) }}</td><td class="right">{{ $montant($taxe['difference']) }}</td></tr>
                @endforeach
                <tr class="total"><td>Total</td><td class="right">{{ $montant($total['collectee']) }}</td><td class="right">{{ $montant($total['deductible']) }}</td><td class="right">{{ $montant($total['difference']) }}</td></tr>
            </tbody>
        </table>
        <p class="note">Différence positive : taxe à reverser ; négative : crédit de taxe à récupérer.</p>

        @if($comptes)
            <h2>Comptes de taxes au journal</h2>
            <table class="sommaire">
                <thead><tr><th>Compte</th><th>Rôle</th><th class="right">Débit</th><th class="right">Crédit</th><th class="right">Solde (crédit − débit)</th></tr></thead>
                <tbody>
                    @foreach($comptes['lignes'] as $compte)
                        <tr>
                            <td>{{ $compte['numero'] }} — {{ $compte['libelle'] }}</td>
                            <td>{{ collect($compte['roles'])->map(fn ($r) => $roles[$r])->join(', ') }}</td>
                            <td class="right">{{ $montant($compte['debit']) }}</td>
                            <td class="right">{{ $montant($compte['credit']) }}</td>
                            <td class="right">{{ $montant($compte['solde']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="total"><td colspan="4">Taxe nette au journal</td><td class="right">{{ $montant($comptes['solde']) }}</td></tr>
                </tbody>
            </table>
            @if(abs($comptes['solde'] - $total['difference']) >= 0.005)
                <p class="note">Écart de {{ $montant($comptes['solde'] - $total['difference']) }} avec le rapport : des documents ne sont pas encore passés au journal, ou des taxes y ont été saisies à la main.</p>
            @endif
        @endif
    </div>
</body>
</html>
