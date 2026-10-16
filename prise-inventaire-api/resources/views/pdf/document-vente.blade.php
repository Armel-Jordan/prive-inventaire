@php
    $montant = fn ($valeur) => number_format((float) $valeur, 2, ',', ' ').' '.$devise;
    $quantite = fn ($valeur) => number_format((float) $valeur, 0, ',', ' ');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ $titre }} {{ $numero }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11px; line-height: 1.4; color: #333; }
        .container { padding: 20px 20px 60px; }
        .header { display: table; width: 100%; margin-bottom: 25px; }
        .header-left, .header-right { display: table-cell; vertical-align: top; width: 50%; }
        .company-name { font-size: 20px; font-weight: bold; color: #2563eb; margin-bottom: 4px; }
        .company-info { font-size: 10px; color: #666; }
        .document-title { text-align: right; }
        .document-title h1 { font-size: 24px; color: #1f2937; margin-bottom: 4px; }
        .document-number { font-size: 15px; font-weight: bold; color: #2563eb; }
        .status { display: inline-block; margin-top: 8px; padding: 3px 10px; border-radius: 4px; background-color: #e5e7eb; font-size: 9px; font-weight: bold; text-transform: uppercase; }
        .info-section { display: table; width: 100%; margin-bottom: 20px; }
        .info-box { display: table-cell; width: 48%; vertical-align: top; }
        .info-box-spacer { display: table-cell; width: 4%; }
        .info-box-title { background-color: #2563eb; color: white; padding: 6px 10px; font-weight: bold; font-size: 10px; text-transform: uppercase; }
        .info-box-content { border: 1px solid #e5e7eb; border-top: none; padding: 10px; min-height: 80px; }
        .info-label { font-size: 9px; color: #6b7280; text-transform: uppercase; }
        .info-value { margin-bottom: 6px; }
        .client-name { font-weight: bold; font-size: 13px; margin-bottom: 4px; }
        .lines { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .lines th { background-color: #2563eb; color: white; padding: 8px 6px; text-align: left; font-size: 10px; text-transform: uppercase; }
        .lines td { padding: 8px 6px; border-bottom: 1px solid #e5e7eb; }
        .lines tr:nth-child(even) td { background-color: #f9fafb; }
        .right, .lines th.right { text-align: right; }
        .totals { width: 320px; margin-left: auto; border-collapse: collapse; margin-bottom: 12px; }
        .totals td { padding: 6px 10px; border: 1px solid #e5e7eb; }
        .totals .label { background-color: #f3f4f6; }
        .totals .strong td { background-color: #2563eb; color: white; font-weight: bold; font-size: 12px; border-color: #2563eb; }
        .mention { text-align: right; font-size: 9px; color: #6b7280; margin-bottom: 12px; }
        .notes-title { font-weight: bold; margin-bottom: 4px; color: #374151; }
        .notes-content { background-color: #f9fafb; border: 1px solid #e5e7eb; padding: 10px; margin-bottom: 20px; }
        .signatures { display: table; width: 100%; margin-top: 30px; }
        .signature { display: table-cell; text-align: center; padding: 0 10px; }
        .signature-line { border-top: 1px solid #333; margin-top: 55px; padding-top: 4px; font-size: 9px; }
        .footer { position: fixed; bottom: 15px; left: 20px; right: 20px; text-align: center; font-size: 8px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 6px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-left">
                <div class="company-name">{{ $entreprise['nom'] }}</div>
                <div class="company-info">
                    @if($entreprise['adresse']){!! nl2br(e($entreprise['adresse'])) !!}<br>@endif
                    @if($entreprise['telephone'])Tél. : {{ $entreprise['telephone'] }}<br>@endif
                    @if($entreprise['email']){{ $entreprise['email'] }}@endif
                </div>
            </div>
            <div class="header-right document-title">
                <h1>{{ $titre }}</h1>
                <div class="document-number">{{ $numero }}</div>
                <div class="status">{{ $statut }}</div>
            </div>
        </div>

        <div class="info-section">
            <div class="info-box">
                <div class="info-box-title">{{ $colonnes === 'livraison' ? 'Livrer à' : 'Client' }}</div>
                <div class="info-box-content">
                    <div class="client-name">{{ $destinataire['nom'] }}</div>
                    @foreach($destinataire['lignes'] as $ligne)
                        <div>{!! nl2br(e($ligne)) !!}</div>
                    @endforeach
                </div>
            </div>
            <div class="info-box-spacer"></div>
            <div class="info-box">
                <div class="info-box-title">Informations</div>
                <div class="info-box-content">
                    @foreach($infos as $libelle => $valeur)
                        <div class="info-label">{{ $libelle }}</div>
                        <div class="info-value">{{ $valeur }}</div>
                    @endforeach
                </div>
            </div>
        </div>

        <table class="lines">
            <thead>
                <tr>
                    <th style="width: 14%;">Référence</th>
                    <th>Désignation</th>
                    @if($colonnes === 'livraison')
                        <th class="right" style="width: 12%;">À livrer</th>
                        <th class="right" style="width: 12%;">Préparé</th>
                        <th class="right" style="width: 12%;">Livré</th>
                    @else
                        <th class="right" style="width: 9%;">Qté</th>
                        <th class="right" style="width: 15%;">Prix unitaire HT</th>
                        <th class="right" style="width: 9%;">Remise</th>
                        <th class="right" style="width: 16%;">Total HT</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($lignes as $ligne)
                    <tr>
                        <td>{{ $ligne['reference'] }}</td>
                        <td>{{ $ligne['designation'] }}</td>
                        @if($colonnes === 'livraison')
                            <td class="right">{{ $quantite($ligne['a_livrer']) }}</td>
                            <td class="right">{{ $quantite($ligne['preparee']) }}</td>
                            <td class="right">{{ $quantite($ligne['livree']) }}</td>
                        @else
                            <td class="right">{{ $quantite($ligne['quantite']) }}</td>
                            <td class="right">{{ $montant($ligne['prix_unitaire']) }}</td>
                            <td class="right">{{ $ligne['remise'] > 0 ? rtrim(rtrim(number_format($ligne['remise'], 2, ',', ' '), '0'), ',').' %' : '' }}</td>
                            <td class="right">{{ $montant($ligne['total']) }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if(count($totaux))
            <table class="totals">
                @foreach($totaux as $total)
                    <tr class="{{ ($total[2] ?? false) ? 'strong' : '' }}">
                        <td class="label">{{ $total[0] }}</td>
                        <td class="right">{{ $montant($total[1]) }}</td>
                    </tr>
                @endforeach
                @foreach($reglement as $ligne)
                    <tr>
                        <td class="label">{{ $ligne[0] }}</td>
                        <td class="right">{{ $montant($ligne[1]) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
        @if($mention)
            <div class="mention">{{ $mention }}</div>
        @endif

        @if($notes)
            <div class="notes-title">Notes</div>
            <div class="notes-content">{!! nl2br(e($notes)) !!}</div>
        @endif

        @if(count($signatures))
            <div class="signatures">
                @foreach($signatures as $signature)
                    <div class="signature"><div class="signature-line">{{ $signature }}</div></div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="footer">
        {{ $entreprise['nom'] }}@if($entreprise['mentions']) — {{ $entreprise['mentions'] }}@endif
        — Document généré le {{ now()->format('d/m/Y à H:i') }}
    </div>
</body>
</html>
