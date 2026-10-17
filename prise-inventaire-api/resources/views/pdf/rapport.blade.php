@php
    // Rapport de la bibliothèque : titre, paramètres, tableau des lignes et totaux (montants dans la devise de l'entreprise).
    $en = $langue === 'en';
    $nombre = fn ($valeur, $decimales) => number_format((float) $valeur, $decimales, $en ? '.' : ',', $en ? ',' : ' ');
    $cellule = function ($valeur, string $type) use ($nombre, $en) {
        if ($valeur === null || $valeur === '') {
            return '';
        }
        return match ($type) {
            'montant' => $nombre($valeur, 2),
            'quantite' => rtrim(rtrim($nombre($valeur, 4), '0'), $en ? '.' : ','),
            'pourcentage' => $nombre($valeur, 1).' %',
            'entier' => $nombre($valeur, 0),
            'date' => \Illuminate\Support\Carbon::parse($valeur)->format($en ? 'Y-m-d' : 'd/m/Y'),
            default => (string) $valeur,
        };
    };
    $aDroite = fn (string $type) => in_array($type, ['montant', 'quantite', 'pourcentage', 'entier'], true);
@endphp
<!DOCTYPE html>
<html lang="{{ $langue }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $titre }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 8.5px; line-height: 1.35; color: #333; }
        .container { padding: 16px; }
        .header { display: table; width: 100%; margin-bottom: 12px; }
        .header-left, .header-right { display: table-cell; vertical-align: top; width: 50%; }
        .company-name { font-size: 15px; font-weight: bold; color: #0d9488; margin-bottom: 3px; }
        .company-info { font-size: 8px; color: #666; }
        .document-title { text-align: right; }
        .document-title h1 { font-size: 15px; color: #1f2937; margin-bottom: 4px; }
        .parametres { font-size: 8px; color: #0d9488; }
        table { width: 100%; border-collapse: collapse; }
        th { background-color: #0d9488; color: white; padding: 4px; text-align: left; font-size: 7.5px; text-transform: uppercase; }
        td { padding: 3px 4px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .right { text-align: right; }
        .total td { font-weight: bold; border-top: 1px solid #9ca3af; }
        .vide { color: #9ca3af; font-style: italic; padding: 6px 4px; }
        .pied { margin-top: 8px; font-size: 7.5px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-left">
                <div class="company-name">{{ $entreprise['nom'] }}</div>
                <div class="company-info">{{ $description }}</div>
            </div>
            <div class="header-right document-title">
                <h1>{{ $titre }}</h1>
                @foreach($parametres as $libelle => $valeur)
                    <div class="parametres">{{ $libelle }} : {{ $valeur }}</div>
                @endforeach
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    @foreach($colonnes as $colonne)
                        <th class="{{ $aDroite($colonne['type']) ? 'right' : '' }}">{{ $colonne['libelle'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($lignes as $ligne)
                    <tr>
                        @foreach($colonnes as $cle => $colonne)
                            <td class="{{ $aDroite($colonne['type']) ? 'right' : '' }}">{{ $cellule($ligne[$cle] ?? null, $colonne['type']) }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td class="vide" colspan="{{ count($colonnes) }}">{{ $en ? 'No data.' : 'Aucune donnée.' }}</td></tr>
                @endforelse
                @if($totaux)
                    <tr class="total">
                        @foreach($colonnes as $cle => $colonne)
                            <td class="{{ $aDroite($colonne['type']) ? 'right' : '' }}">{{ $loop->first && ! array_key_exists($cle, $totaux) ? ($en ? 'Total' : 'Total') : $cellule($totaux[$cle] ?? null, $colonne['type']) }}</td>
                        @endforeach
                    </tr>
                @endif
            </tbody>
        </table>
        <div class="pied">{{ $en ? 'Amounts in' : 'Montants en' }} {{ $devise }} — {{ $en ? 'generated on' : 'généré le' }} {{ $genere }}</div>
    </div>
</body>
</html>
