@php
    $nombre = fn ($valeur, $decimales = 0) => number_format((float) $valeur, $decimales, ',', ' ');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Matières dangereuses — tournée {{ $tournee->numero }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11px; line-height: 1.4; color: #333; }
        .container { padding: 20px; }
        .header { display: table; width: 100%; margin-bottom: 18px; }
        .header-left, .header-right { display: table-cell; vertical-align: top; width: 50%; }
        .company-name { font-size: 20px; font-weight: bold; color: #b91c1c; margin-bottom: 4px; }
        .company-info { font-size: 10px; color: #666; }
        .document-title { text-align: right; }
        .document-title h1 { font-size: 16px; color: #1f2937; margin-bottom: 4px; text-transform: uppercase; }
        .document-number { font-size: 13px; font-weight: bold; color: #b91c1c; }
        .urgence { margin: 0 0 14px; border: 2px solid #b91c1c; padding: 8px 12px; font-size: 13px; font-weight: bold; color: #b91c1c; }
        .info { display: table; width: 100%; margin-bottom: 16px; }
        .info-cell { display: table-cell; width: 25%; vertical-align: top; padding-right: 8px; }
        .info-label { font-size: 9px; color: #6b7280; text-transform: uppercase; }
        .info-value { font-size: 11px; font-weight: bold; }
        .lignes { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .lignes th { background-color: #b91c1c; color: white; padding: 7px 6px; text-align: left; font-size: 9px; text-transform: uppercase; }
        .lignes td { padding: 7px 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .lignes .right { text-align: right; }
        .petit { font-size: 9px; color: #6b7280; }
        .total { width: 260px; margin-left: auto; margin-bottom: 18px; border-collapse: collapse; }
        .total td { padding: 6px 10px; border: 1px solid #e5e7eb; }
        .total .label { background-color: #f3f4f6; font-weight: bold; }
        .declaration { border: 1px solid #e5e7eb; background-color: #f9fafb; padding: 10px 12px; margin-bottom: 26px; font-size: 10px; }
        .signatures { display: table; width: 100%; }
        .signature { display: table-cell; width: 50%; text-align: center; padding: 0 10px; }
        .signature-line { border-top: 1px solid #333; margin-top: 50px; padding-top: 4px; font-size: 9px; }
        .footer { position: fixed; bottom: 20px; left: 20px; right: 20px; text-align: center; font-size: 9px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 8px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-left">
                <div class="company-name">{{ $entreprise['nom'] }}</div>
                <div class="company-info">
                    @foreach (array_filter([$entreprise['adresse'], $entreprise['telephone'], $entreprise['email']]) as $coordonnee)
                        {{ $coordonnee }}@if (! $loop->last)<br>@endif
                    @endforeach
                </div>
            </div>
            <div class="header-right document-title">
                <h1>Document de transport<br>Marchandises dangereuses</h1>
                <div class="document-number">Tournée {{ $tournee->numero }}</div>
            </div>
        </div>

        @if($telephoneUrgence)
            <div class="urgence">En cas d'urgence : {{ $telephoneUrgence }}</div>
        @endif

        <div class="info">
            <div class="info-cell"><div class="info-label">Date</div><div class="info-value">{{ $tournee->date_tournee?->format('d/m/Y') }}</div></div>
            <div class="info-cell"><div class="info-label">Véhicule</div><div class="info-value">{{ $tournee->camion?->immatriculation ?? '—' }}</div>
                @if($tournee->camion)<div class="petit">{{ trim($tournee->camion->marque.' '.$tournee->camion->modele) }}</div>@endif</div>
            <div class="info-cell"><div class="info-label">Zone</div><div class="info-value">{{ $tournee->zone ?: '—' }}</div></div>
            <div class="info-cell"><div class="info-label">Classes à bord</div><div class="info-value">{{ $classes->implode(', ') }}</div></div>
        </div>

        <table class="lignes">
            <thead>
                <tr>
                    <th style="width: 11%;">N° ONU</th>
                    <th style="width: 31%;">Désignation</th>
                    <th style="width: 8%;">Classe</th>
                    <th style="width: 8%;">Groupe</th>
                    <th style="width: 20%;">Bon / client</th>
                    <th class="right" style="width: 10%;">Quantité</th>
                    <th class="right" style="width: 12%;">Masse brute</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lignes as $ligne)
                    <tr>
                        <td><strong>{{ $ligne['numero_onu'] ?: '—' }}</strong></td>
                        <td>{{ $ligne['designation'] }}</td>
                        <td>{{ $ligne['classe'] ?: '—' }}</td>
                        <td>{{ $ligne['groupe'] ?: '—' }}</td>
                        <td>{{ $ligne['bon'] }}@if($ligne['client'])<div class="petit">{{ $ligne['client'] }}</div>@endif</td>
                        <td class="right">{{ $nombre($ligne['quantite']) }} {{ $ligne['unite'] }}</td>
                        <td class="right">{{ $ligne['masse_kg'] !== null ? $nombre($ligne['masse_kg'], 2).' kg' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="total">
            <tr>
                <td class="label">Masse brute totale</td>
                <td style="text-align: right;">{{ $nombre($masseTotale, 2) }} kg</td>
            </tr>
        </table>
        @if($masseIncomplete)
            <p class="petit" style="margin: -10px 0 14px; text-align: right;">Poids inconnu pour un produit au moins (« — ») : la masse totale est incomplète.</p>
        @endif

        <div class="declaration">{!! nl2br(e($declaration)) !!}</div>

        <div class="signatures">
            <div class="signature"><div class="signature-line">Expéditeur — nom, date et signature</div></div>
            <div class="signature"><div class="signature-line">Conducteur — nom, date et signature</div></div>
        </div>
    </div>

    <div class="footer">{{ $entreprise['nom'] }} — tournée {{ $tournee->numero }}</div>
</body>
</html>
