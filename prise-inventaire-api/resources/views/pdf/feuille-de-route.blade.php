<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Feuille de route — tournée {{ $tournee->numero }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10px; line-height: 1.35; color: #333; }
        .container { padding: 18px; }
        .header { display: table; width: 100%; margin-bottom: 12px; }
        .header-left, .header-right { display: table-cell; vertical-align: top; width: 50%; }
        .company-name { font-size: 18px; font-weight: bold; color: #4f46e5; margin-bottom: 3px; }
        .company-info { font-size: 9px; color: #666; }
        .document-title { text-align: right; }
        .document-title h1 { font-size: 18px; color: #1f2937; text-transform: uppercase; }
        .document-number { font-size: 13px; font-weight: bold; color: #4f46e5; }
        .info { display: table; width: 100%; margin-bottom: 10px; border: 1px solid #e5e7eb; }
        .info-cell { display: table-cell; width: 20%; vertical-align: top; padding: 6px 8px; border-right: 1px solid #e5e7eb; }
        .info-cell:last-child { border-right: none; }
        .info-label { font-size: 8px; color: #6b7280; text-transform: uppercase; }
        .info-value { font-size: 11px; font-weight: bold; }
        .arrets { width: 100%; border-collapse: collapse; }
        .arrets th { background-color: #4f46e5; color: white; padding: 6px 5px; text-align: left; font-size: 8px; text-transform: uppercase; }
        .arrets td { padding: 7px 5px; border: 1px solid #e5e7eb; vertical-align: top; }
        .arrets .ordre { font-size: 14px; font-weight: bold; text-align: center; }
        .arrets .vide { height: 34px; }
        .petit { font-size: 8px; color: #6b7280; }
        .instructions { font-size: 8px; color: #92400e; margin-top: 2px; }
        .bas { display: table; width: 100%; margin-top: 14px; }
        .bas-cell { display: table-cell; width: 33%; padding-right: 10px; font-size: 9px; }
        .ligne { border-bottom: 1px solid #333; display: inline-block; width: 70px; }
        .footer { position: fixed; bottom: 14px; left: 18px; right: 18px; text-align: center; font-size: 8px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-left">
                <div class="company-name">{{ $entreprise['nom'] }}</div>
                <div class="company-info">{{ implode(' — ', array_filter([$entreprise['adresse'], $entreprise['telephone']])) }}</div>
            </div>
            <div class="header-right document-title">
                <h1>Feuille de route</h1>
                <div class="document-number">Tournée {{ $tournee->numero }} — {{ count($arrets) }} arrêt(s)</div>
            </div>
        </div>

        <div class="info">
            <div class="info-cell"><div class="info-label">Date</div><div class="info-value">{{ $tournee->date_tournee?->format('d/m/Y') }}</div></div>
            <div class="info-cell"><div class="info-label">Véhicule</div><div class="info-value">{{ $tournee->camion?->immatriculation ?? '—' }}</div></div>
            <div class="info-cell"><div class="info-label">Route</div><div class="info-value">{{ $tournee->itineraire ? $tournee->itineraire->code.' — '.$tournee->itineraire->nom : '—' }}</div></div>
            <div class="info-cell"><div class="info-label">Zone</div><div class="info-value">{{ $tournee->zone ?: '—' }}</div></div>
            <div class="info-cell"><div class="info-label">Livreur</div><div class="info-value">&nbsp;</div></div>
        </div>

        <table class="arrets">
            <thead>
                <tr>
                    <th style="width: 4%;">N°</th>
                    <th style="width: 11%;">Bon / facture</th>
                    <th style="width: 17%;">Client</th>
                    <th style="width: 25%;">Adresse et instructions</th>
                    <th style="width: 12%;">Téléphone</th>
                    <th style="width: 7%;">Qté</th>
                    <th style="width: 14%;">Reçu par / signature</th>
                    <th style="width: 10%;">Remarques</th>
                </tr>
            </thead>
            <tbody>
                @foreach($arrets as $arret)
                    <tr>
                        <td class="ordre">{{ $arret['ordre'] }}</td>
                        <td><strong>{{ $arret['bon'] }}</strong><div class="petit">{{ $arret['facture'] }}</div></td>
                        <td>{{ $arret['client'] }}@if($arret['sequence'] !== null)<div class="petit">Séquence {{ $arret['sequence'] }}</div>@endif</td>
                        <td>{{ $arret['adresse'] ?: '—' }}@if($arret['instructions'])<div class="instructions">{{ $arret['instructions'] }}</div>@endif</td>
                        <td>{{ $arret['telephone'] ?: '—' }}@if($arret['contact'])<div class="petit">{{ $arret['contact'] }}</div>@endif</td>
                        <td>{{ $arret['quantite'] }}<div class="petit">{{ $arret['lignes'] }} ligne(s)</div></td>
                        <td class="vide"></td>
                        <td class="vide"></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="bas">
            <div class="bas-cell">Départ : <span class="ligne"></span> h, <span class="ligne"></span> km</div>
            <div class="bas-cell">Retour : <span class="ligne"></span> h, <span class="ligne"></span> km</div>
            <div class="bas-cell">Signature du livreur : <span class="ligne" style="width: 120px;"></span></div>
        </div>
    </div>

    <div class="footer">{{ $entreprise['nom'] }} — tournée {{ $tournee->numero }}</div>
</body>
</html>
