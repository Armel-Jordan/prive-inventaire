@php
    $nombre = fn ($valeur) => number_format((float) $valeur, 2, ',', ' ');
    $date = fn ($valeur) => \Illuminate\Support\Carbon::parse($valeur)->format('d/m/Y');
    $compte = $rapprochement->compteBancaire;
    $groupes = [
        'Entrées pointées' => collect($lignes)->where('pointee', true)->where('entree', '>', 0),
        'Sorties pointées' => collect($lignes)->where('pointee', true)->where('sortie', '>', 0),
        'Entrées en circulation (pas encore sur le relevé)' => collect($lignes)->where('pointee', false)->where('entree', '>', 0),
        'Sorties en circulation (pas encore sur le relevé)' => collect($lignes)->where('pointee', false)->where('sortie', '>', 0),
    ];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Rapprochement bancaire {{ $compte->nom }} au {{ $date($rapprochement->date_releve) }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10px; line-height: 1.4; color: #333; }
        .container { padding: 20px; }
        .header { display: table; width: 100%; margin-bottom: 18px; }
        .header-left, .header-right { display: table-cell; vertical-align: top; width: 50%; }
        .company-name { font-size: 18px; font-weight: bold; color: #0d9488; margin-bottom: 4px; }
        .company-info { font-size: 9px; color: #666; }
        .document-title { text-align: right; }
        .document-title h1 { font-size: 18px; color: #1f2937; margin-bottom: 4px; }
        .sous-titre { font-size: 11px; color: #0d9488; font-weight: bold; }
        .statut { margin-top: 6px; font-size: 10px; color: #6b7280; }
        h2 { font-size: 11px; color: #0d9488; margin: 14px 0 6px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
        th { background-color: #0d9488; color: white; padding: 5px; text-align: left; font-size: 8px; text-transform: uppercase; }
        td { padding: 4px 5px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .right { text-align: right; }
        .total td { font-weight: bold; border-top: 1px solid #9ca3af; }
        .preuve { width: 60%; margin-left: auto; margin-top: 16px; }
        .preuve td { border: 1px solid #e5e7eb; }
        .preuve .final td { background-color: #0d9488; color: white; font-weight: bold; }
        .vide { color: #9ca3af; font-style: italic; padding: 4px 5px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-left">
                <div class="company-name">{{ $entreprise['nom'] }}</div>
                <div class="company-info">{{ $compte->banque }} — {{ $compte->nom }}@if($compte->numero_compte) — n° {{ $compte->numero_compte }}@endif</div>
            </div>
            <div class="header-right document-title">
                <h1>RAPPROCHEMENT BANCAIRE</h1>
                <div class="sous-titre">Relevé au {{ $date($rapprochement->date_releve) }}</div>
                <div class="statut">
                    @if($rapprochement->statut === 'termine')
                        Terminé le {{ $rapprochement->termine_le?->format('d/m/Y') }}@if($rapprochement->auteurFin) par {{ $rapprochement->auteurFin->nom }}@endif
                    @else
                        En cours
                    @endif
                </div>
            </div>
        </div>

        @foreach($groupes as $titre => $groupe)
            <h2>{{ $titre }}</h2>
            @if($groupe->isEmpty())
                <div class="vide">Aucun mouvement.</div>
            @else
                <table>
                    <thead>
                        <tr>
                            <th style="width: 12%;">Date</th>
                            <th style="width: 18%;">Écriture</th>
                            <th style="width: 40%;">Libellé</th>
                            <th style="width: 15%;">Référence</th>
                            <th class="right" style="width: 15%;">Montant</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($groupe as $ligne)
                            <tr>
                                <td>{{ $date($ligne['date']) }}</td>
                                <td>{{ $ligne['journal'] }} {{ $ligne['numero'] }}</td>
                                <td>{{ $ligne['libelle'] }}</td>
                                <td>{{ $ligne['reference'] }}</td>
                                <td class="right">{{ $nombre($ligne['entree'] ?: $ligne['sortie']) }} {{ $devise }}</td>
                            </tr>
                        @endforeach
                        <tr class="total">
                            <td colspan="4">Total</td>
                            <td class="right">{{ $nombre($groupe->sum('entree') + $groupe->sum('sortie')) }} {{ $devise }}</td>
                        </tr>
                    </tbody>
                </table>
            @endif
        @endforeach

        <table class="preuve">
            <tr><td>Solde de départ</td><td class="right">{{ $nombre($resume['solde_depart']) }} {{ $devise }}</td></tr>
            <tr><td>+ Entrées pointées</td><td class="right">{{ $nombre($resume['entrees_pointees']) }} {{ $devise }}</td></tr>
            <tr><td>− Sorties pointées</td><td class="right">{{ $nombre($resume['sorties_pointees']) }} {{ $devise }}</td></tr>
            <tr><td><strong>Solde pointé</strong></td><td class="right"><strong>{{ $nombre($resume['solde_pointe']) }} {{ $devise }}</strong></td></tr>
            <tr><td>Solde du relevé</td><td class="right">{{ $nombre($resume['solde_releve']) }} {{ $devise }}</td></tr>
            <tr><td>Écart</td><td class="right">{{ $nombre($resume['ecart']) }} {{ $devise }}</td></tr>
            <tr><td>+ Entrées en circulation</td><td class="right">{{ $nombre($resume['entrees_en_circulation']) }} {{ $devise }}</td></tr>
            <tr><td>− Sorties en circulation</td><td class="right">{{ $nombre($resume['sorties_en_circulation']) }} {{ $devise }}</td></tr>
            <tr class="final"><td>Solde comptable au {{ $date($rapprochement->date_releve) }}</td><td class="right">{{ $nombre($resume['solde_comptable']) }} {{ $devise }}</td></tr>
        </table>
    </div>
</body>
</html>
