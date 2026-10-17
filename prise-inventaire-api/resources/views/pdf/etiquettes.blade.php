@php
    // Zones de l'étiquette, de haut en bas (mm) ; sans code-barres, le texte est centré verticalement. Les textes
    // arrivent ajustés à leur place (App\Services\Etiquettes) : rien n'est coupé ici.
    $d = $disposition;
    $hauteur = $format->hauteur_mm;
    $largeur = $format->largeur_mm;
    $haut = $d['marge'] + ($d['libre'] > 0 ? round($d['libre'] / 2, 2) : 0);
    $zones = [];
    foreach (['entreprise', 'description', 'code_barres', 'chiffres', 'bas', 'date'] as $zone) {
        $epaisseur = $d[$zone] + ($zone === 'code_barres' && $d[$zone] > 0 ? 2 * $blanc : 0);
        $zones[$zone] = round($haut, 2);
        $haut += $epaisseur;
    }
    $ligne = fn (float $points) => \App\Services\Etiquettes::hauteurLigne($points);
    // DomPDF pose la ligne de base au bas de la boîte de ligne et laisse dépasser les jambages (0,24 em pour DejaVu) :
    // la boîte est raccourcie d'autant pour que le texte reste dans sa zone.
    $boite = fn (float $hauteur, float $points) => round($hauteur - $points * 0.3528 * 0.24, 2);
    $police = $d['police'];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: 'DejaVu Sans', sans-serif; color: #000; }
        .page { position: relative; width: {{ $largeurPage }}mm; height: {{ $hauteurPage - 0.05 }}mm; overflow: hidden; }
        .etiquette { position: absolute; width: {{ $largeur }}mm; height: {{ $hauteur }}mm; overflow: hidden; }
        .contour { position: absolute; left: 0; top: 0; width: {{ $largeur - 0.2 }}mm; height: {{ $hauteur - 0.2 }}mm; border: 0.1mm dashed #888; }
        .zone { position: absolute; left: {{ $d['marge'] }}mm; width: {{ $d['interieur'] }}mm; white-space: nowrap; }
        .entreprise { font-size: {{ $police - 1 }}pt; line-height: {{ $boite($ligne($police - 1), $police - 1) }}mm; font-weight: bold; text-align: center; }
        .description { font-size: {{ $police }}pt; line-height: {{ $boite($d['ligne'], $police) }}mm; font-weight: bold; text-align: center; }
        .chiffres { font-size: {{ $police - 1 }}pt; line-height: {{ $boite($ligne($police - 1), $police - 1) }}mm; text-align: center; letter-spacing: {{ $espacement }}pt; }
        {{-- Numéro et prix sur la même ligne de base, celle du prix. --}}
        .numero { font-size: {{ $police - 1 }}pt; line-height: {{ $boite($d['bas'], $police + 3) }}mm; text-align: left; }
        .prix { line-height: {{ $boite($d['bas'], $police + 3) }}mm; font-weight: bold; text-align: right; }
        .date { font-size: {{ max(4, $police - 2) }}pt; line-height: {{ $boite($ligne($police - 2), $police - 2) }}mm; text-align: center; }
        .code-barres { display: block; width: {{ $d['interieur'] }}mm; height: {{ $d['code_barres'] }}mm; }
    </style>
</head>
<body>
@foreach ($pages as $page)
    <div class="page" @if (! $loop->last) style="page-break-after: always;" @endif>
        @foreach ($page as $place)
            @php($e = $place['etiquette'])
            @php($t = $e['texte'])
            <div class="etiquette" style="left: {{ $place['x'] }}mm; top: {{ $place['y'] }}mm;">
                @if ($contours)<div class="contour"></div>@endif
                @isset($a['entreprise'])
                    <div class="zone entreprise" style="top: {{ $zones['entreprise'] }}mm;">{{ $entreprise }}</div>
                @endisset
                {{-- Une description plus courte que sa zone y est centrée. --}}
                @php($decalage = ($d['lignes_description'] - count($t['description'])) * $d['ligne'] / 2)
                @foreach ($t['description'] as $rang => $texte)
                    <div class="zone description" style="top: {{ round($zones['description'] + $decalage + $rang * $d['ligne'], 2) }}mm;">{{ $texte }}</div>
                @endforeach
                @isset($a['code_barres'])
                    <div class="zone" style="top: {{ round($zones['code_barres'] + $blanc, 2) }}mm;">
                        {{-- Un code qu'aucune symbologie n'écrit (caractères accentués) : son texte. --}}
                        @if ($e['image'])<img class="code-barres" src="{{ $e['image'] }}" alt="">@else<div class="chiffres" style="line-height: {{ $boite($d['code_barres'], $police - 1) }}mm;">{{ $t['code'] }}</div>@endif
                    </div>
                    @isset($a['chiffres'])
                        <div class="zone chiffres" style="top: {{ $zones['chiffres'] }}mm;">{{ $t['code'] }}</div>
                    @endisset
                @endisset
                @if ($t['numero'] !== null)
                    <div class="zone numero" style="top: {{ $zones['bas'] }}mm;">{{ $t['numero'] }}</div>
                @endif
                @if (isset($a['prix']) && $e['prix'] !== null)
                    <div class="zone prix" style="top: {{ $zones['bas'] }}mm; font-size: {{ $t['taille_prix'] }}pt;">{{ $e['prix'] }}</div>
                @endif
                @isset($a['date'])
                    <div class="zone date" style="top: {{ $zones['date'] }}mm;">{{ $date }}</div>
                @endisset
            </div>
        @endforeach
    </div>
@endforeach
</body>
</html>
