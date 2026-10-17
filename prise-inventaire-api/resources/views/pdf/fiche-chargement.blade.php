<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Fiche de chargement — tournée {{ $tournee->numero }}</title>
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
        .info { display: table; width: 100%; margin-bottom: 8px; border: 1px solid #e5e7eb; }
        .info-cell { display: table-cell; width: 25%; vertical-align: top; padding: 6px 8px; border-right: 1px solid #e5e7eb; }
        .info-cell:last-child { border-right: none; }
        .info-label { font-size: 8px; color: #6b7280; text-transform: uppercase; }
        .info-value { font-size: 11px; font-weight: bold; }
        .consigne { font-size: 9px; color: #92400e; margin-bottom: 8px; }
        .lignes { width: 100%; border-collapse: collapse; }
        .lignes th { background-color: #4f46e5; color: white; padding: 6px 5px; text-align: left; font-size: 8px; text-transform: uppercase; }
        .lignes td { padding: 6px 5px; border: 1px solid #e5e7eb; vertical-align: top; }
        .lignes .rang { font-size: 14px; font-weight: bold; text-align: center; }
        .lignes .case { text-align: center; }
        .carre { display: inline-block; width: 11px; height: 11px; border: 1px solid #333; }
        .coche { color: #15803d; font-weight: bold; }
        .vrac { color: #6b7280; font-style: italic; }
        .petit { font-size: 8px; color: #6b7280; }
        .total { margin-top: 8px; font-size: 10px; }
        .bas { display: table; width: 100%; margin-top: 16px; }
        .bas-cell { display: table-cell; width: 50%; padding-right: 10px; font-size: 9px; }
        .ligne { border-bottom: 1px solid #333; display: inline-block; width: 110px; }
        .footer { position: fixed; bottom: 14px; left: 18px; right: 18px; text-align: center; font-size: 8px; color: #9ca3af; }
    </style>
</head>
<body>
    @php
        $contenu = fn (array $elements) => implode(', ', array_map(fn ($e) => ($e['produit']['description'] ?? '?').' × '.$e['quantite'], $elements));
        $kg = fn ($valeur) => rtrim(rtrim(number_format((float) $valeur, 3, ',', ' '), '0'), ',').' kg';
    @endphp
    <div class="container">
        <div class="header">
            <div class="header-left">
                <div class="company-name">{{ $entreprise['nom'] }}</div>
                <div class="company-info">{{ implode(' — ', array_filter([$entreprise['adresse'], $entreprise['telephone']])) }}</div>
            </div>
            <div class="header-right document-title">
                <h1>Fiche de chargement</h1>
                <div class="document-number">Tournée {{ $tournee->numero }} — {{ count($arrets) }} arrêt(s)</div>
            </div>
        </div>

        <div class="info">
            <div class="info-cell"><div class="info-label">Date</div><div class="info-value">{{ $tournee->date_tournee?->format('d/m/Y') }}</div></div>
            <div class="info-cell"><div class="info-label">Véhicule</div><div class="info-value">{{ $tournee->camion?->immatriculation ?? '—' }}</div></div>
            <div class="info-cell"><div class="info-label">Route</div><div class="info-value">{{ $tournee->itineraire ? $tournee->itineraire->code.' — '.$tournee->itineraire->nom : '—' }}</div></div>
            <div class="info-cell"><div class="info-label">Chargé</div><div class="info-value">{{ $resume['colis_charges'] + $resume['vrac_charges'] }} / {{ $resume['colis'] + $resume['vrac'] }}</div></div>
        </div>

        <p class="consigne">Charger dans cet ordre : le dernier arrêt au fond du camion, le premier près des portes.</p>

        <table class="lignes">
            <thead>
                <tr>
                    <th style="width: 6%;">Rang</th>
                    <th style="width: 20%;">Arrêt</th>
                    <th style="width: 16%;">Colis</th>
                    <th style="width: 34%;">Contenu</th>
                    <th style="width: 8%;">Poids</th>
                    <th style="width: 16%;">Chargé</th>
                </tr>
            </thead>
            <tbody>
                @foreach($arrets as $rang => $arret)
                    @php
                        $elements = $arret['colis'];
                        if ($arret['vrac'] !== []) {
                            $elements[] = ['vrac' => true];
                        }
                        $hauteur = max(1, count($elements));
                    @endphp
                    @forelse($elements as $i => $element)
                        <tr>
                            @if($i === 0)
                                <td class="rang" rowspan="{{ $hauteur }}">{{ $rang + 1 }}</td>
                                <td rowspan="{{ $hauteur }}"><strong>{{ $arret['bon'] }}</strong> — arrêt n° {{ $arret['ordre'] }}<div class="petit">{{ $arret['client'] }}</div></td>
                            @endif
                            @if(isset($element['vrac']))
                                <td class="vrac">Hors colis</td>
                                <td>{{ $contenu($arret['vrac']) }}</td>
                                <td></td>
                                <td class="case">@if($arret['vrac_charge_le'])<span class="coche">&#10004;</span><div class="petit">{{ $arret['vrac_charge_par'] }}</div>@else<span class="carre"></span>@endif</td>
                            @else
                                <td><strong>{{ $element['numero'] }}</strong></td>
                                <td>{{ $contenu($element['contenu']) }}</td>
                                <td>{{ $element['poids_kg'] !== null ? $kg($element['poids_kg']) : '' }}</td>
                                <td class="case">@if($element['charge_le'])<span class="coche">&#10004;</span><div class="petit">{{ $element['charge_par'] }}</div>@else<span class="carre"></span>@endif</td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td class="rang">{{ $rang + 1 }}</td>
                            <td><strong>{{ $arret['bon'] }}</strong> — arrêt n° {{ $arret['ordre'] }}<div class="petit">{{ $arret['client'] }}</div></td>
                            <td colspan="4" class="petit">Rien à charger.</td>
                        </tr>
                    @endforelse
                @endforeach
            </tbody>
        </table>

        <p class="total">
            {{ $resume['colis'] }} colis{{ $poids > 0 ? ' ('.$kg($poids).')' : '' }}, {{ $resume['vrac'] }} lot(s) hors colis.
        </p>

        <div class="bas">
            <div class="bas-cell">Chargé par : <span class="ligne"></span> à <span class="ligne" style="width: 50px;"></span> h</div>
            <div class="bas-cell">Contrôlé par le chauffeur : <span class="ligne"></span></div>
        </div>
    </div>

    <div class="footer">{{ $entreprise['nom'] }} — tournée {{ $tournee->numero }}</div>
</body>
</html>
