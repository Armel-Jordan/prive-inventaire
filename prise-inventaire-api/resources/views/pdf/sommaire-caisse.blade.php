<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Sommaire de caisse</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 9px; line-height: 1.35; color: #333; }
        .container { padding: 18px; }
        .header { display: table; width: 100%; margin-bottom: 8px; }
        .header-left, .header-right { display: table-cell; vertical-align: top; width: 50%; }
        .company-name { font-size: 16px; font-weight: bold; color: #4f46e5; }
        .document-title { text-align: right; }
        .document-title h1 { font-size: 16px; color: #1f2937; text-transform: uppercase; }
        .infos { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .infos td { padding: 3px 6px; border: 1px solid #e5e7eb; vertical-align: top; }
        .infos .libelle { color: #6b7280; width: 14%; }
        h2 { font-size: 11px; color: #1f2937; margin: 12px 0 4px; }
        .lignes { width: 100%; border-collapse: collapse; }
        .lignes th { background-color: #4f46e5; color: white; padding: 4px 5px; text-align: left; font-size: 7.5px; text-transform: uppercase; }
        .lignes td { padding: 3px 5px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .total td { font-weight: bold; border-top: 1.5px solid #333; }
        .right { text-align: right; }
        .ecart { color: #b91c1c; font-weight: bold; }
        .vide { color: #9ca3af; }
        .footer { position: fixed; bottom: 12px; left: 18px; right: 18px; text-align: center; font-size: 8px; color: #9ca3af; }
    </style>
</head>
<body>
    @php
        $montant = fn ($valeur, $devise = null) => $valeur === null ? '—' : number_format((float) $valeur, 2, ',', ' ').' '.($symboles[(string) $devise] ?? $devise);
        $instant = fn ($date) => $date ? \Carbon\Carbon::parse($date)->setTimezone($fuseau)->format('d/m/Y H:i') : '';
        $mode = fn ($code, $devise = null) => ($modes[$code] ?? $code).($devise ? " ({$devise})" : '');
        // Remboursements et sorties en négatif (sans « -0,00 »).
        $moins = fn ($valeur) => abs((float) $valeur) < 0.005 ? 0.0 : -(float) $valeur;
        $classeEcart = fn ($ecart) => $ecart !== null && abs((float) $ecart) >= 0.005 ? 'right ecart' : 'right';
        $caisse = $sommaire['caisse'] ?? [];
    @endphp
    <div class="container">
        <div class="header">
            <div class="header-left"><div class="company-name">{{ $entreprise['nom'] }}</div></div>
            <div class="header-right document-title">
                <h1>Sommaire de caisse</h1>
                <div>{{ $caisse['code'] ?? '' }} - {{ $caisse['nom'] ?? '' }}</div>
            </div>
        </div>

        <table class="infos">
            <tr>
                <td class="libelle">Ouverture</td>
                <td>{{ $instant($sommaire['ouverte_le']) }}{{ !empty($sommaire['ouvreur']['nom']) ? ' — '.$sommaire['ouvreur']['nom'] : '' }}</td>
                <td class="libelle">Fermeture</td>
                <td>{{ $instant($sommaire['fermee_le']) }}{{ !empty($sommaire['fermeur']['nom']) ? ' — '.$sommaire['fermeur']['nom'] : '' }}</td>
            </tr>
            @if(!empty($sommaire['note_fermeture']))
                <tr><td class="libelle">Note de fermeture</td><td colspan="3">{{ $sommaire['note_fermeture'] }}</td></tr>
            @endif
        </table>

        <h2>Sommaire par mode de paiement</h2>
        <table class="lignes">
            <thead>
                <tr>
                    <th style="width: 13%;">Mode</th>
                    <th class="right">Fond</th>
                    <th class="right">Encaissements</th>
                    <th class="right">Acomptes</th>
                    <th class="right">Remboursements</th>
                    <th class="right">Entrées</th>
                    <th class="right">Sorties</th>
                    <th class="right">Attendu</th>
                    <th class="right">Compté</th>
                    <th class="right">Écart</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sommaire['lignes'] as $ligne)
                    <tr>
                        <td>{{ $mode($ligne['mode'], $ligne['devise']) }}</td>
                        <td class="right">{{ $montant($ligne['fond'], $ligne['devise']) }}</td>
                        <td class="right">{{ $montant($ligne['encaissements'], $ligne['devise']) }}</td>
                        <td class="right">{{ $montant($ligne['acomptes'], $ligne['devise']) }}</td>
                        <td class="right">{{ $montant($moins($ligne['remboursements']), $ligne['devise']) }}</td>
                        <td class="right">{{ $montant($ligne['entrees'], $ligne['devise']) }}</td>
                        <td class="right">{{ $montant($moins($ligne['sorties']), $ligne['devise']) }}</td>
                        <td class="right">{{ $montant($ligne['attendu'], $ligne['devise']) }}</td>
                        <td class="right">{{ $montant($ligne['compte'], $ligne['devise']) }}</td>
                        <td class="{{ $classeEcart($ligne['ecart']) }}">{{ $montant($ligne['ecart'], $ligne['devise']) }}</td>
                    </tr>
                @endforeach
                @foreach($sommaire['totaux'] as $total)
                    <tr class="total">
                        <td>Total{{ $total['devise'] ? ' '.$total['devise'] : '' }}</td>
                        <td class="right">{{ $montant($total['fond'], $total['devise']) }}</td>
                        <td class="right">{{ $montant($total['encaissements'], $total['devise']) }}</td>
                        <td class="right">{{ $montant($total['acomptes'], $total['devise']) }}</td>
                        <td class="right">{{ $montant($moins($total['remboursements']), $total['devise']) }}</td>
                        <td class="right">{{ $montant($total['entrees'], $total['devise']) }}</td>
                        <td class="right">{{ $montant($moins($total['sorties']), $total['devise']) }}</td>
                        <td class="right">{{ $montant($total['attendu'], $total['devise']) }}</td>
                        <td class="right">{{ $montant($total['compte'], $total['devise']) }}</td>
                        <td class="{{ $classeEcart($total['ecart']) }}">{{ $montant($total['ecart'], $total['devise']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h2>Détail des opérations</h2>
        <table class="lignes">
            <thead>
                <tr>
                    <th style="width: 10%;">Date / heure</th>
                    <th style="width: 12%;">Type</th>
                    <th style="width: 14%;">Document</th>
                    <th style="width: 17%;">Client</th>
                    <th style="width: 9%;">Mode</th>
                    <th class="right" style="width: 10%;">Montant</th>
                    <th style="width: 11%;">Employé</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
                @forelse($sommaire['operations'] as $operation)
                    <tr>
                        <td>{{ $instant($operation['date']) }}</td>
                        <td>{{ $types[$operation['type']] ?? $operation['type'] }}</td>
                        <td>{{ $operation['document'] }}</td>
                        <td>{{ $operation['client'] }}</td>
                        <td>{{ $mode($operation['mode'], $operation['devise']) }}</td>
                        <td class="right">{{ $montant($operation['montant'], $operation['devise']) }}</td>
                        <td>{{ $operation['commis'] }}</td>
                        <td>{{ $operation['note'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="vide">Aucune opération pendant la session.</td></tr>
                @endforelse
            </tbody>
        </table>

        <h2>Encaissements par employé</h2>
        <table class="lignes">
            <thead>
                <tr>
                    <th style="width: 30%;">Employé</th>
                    <th style="width: 25%;">Mode</th>
                    <th class="right" style="width: 15%;">Opérations</th>
                    <th class="right">Montant</th>
                </tr>
            </thead>
            <tbody>
                @forelse($sommaire['par_usager'] as $groupe)
                    <tr>
                        <td>{{ $groupe['commis'] ?? '—' }}</td>
                        <td>{{ $mode($groupe['mode'], $groupe['devise']) }}</td>
                        <td class="right">{{ $groupe['nombre'] }}</td>
                        <td class="right">{{ $montant($groupe['montant'], $groupe['devise']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="vide">Aucun encaissement pendant la session.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if(count($sommaire['sans_caisse']['operations']) > 0)
            <h2>Encaissements faits hors caisse pendant la session</h2>
            <table class="lignes">
                <thead>
                    <tr>
                        <th style="width: 10%;">Date / heure</th>
                        <th style="width: 12%;">Type</th>
                        <th style="width: 14%;">Document</th>
                        <th style="width: 17%;">Client</th>
                        <th style="width: 9%;">Mode</th>
                        <th class="right" style="width: 10%;">Montant</th>
                        <th style="width: 11%;">Employé</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sommaire['sans_caisse']['operations'] as $operation)
                        <tr>
                            <td>{{ $instant($operation['date']) }}</td>
                            <td>{{ $types[$operation['type']] ?? $operation['type'] }}</td>
                            <td>{{ $operation['document'] }}</td>
                            <td>{{ $operation['client'] }}</td>
                            <td>{{ $mode($operation['mode'], $operation['devise']) }}</td>
                            <td class="right">{{ $montant($operation['montant'], $operation['devise']) }}</td>
                            <td>{{ $operation['commis'] }}</td>
                            <td>{{ $operation['note'] }}</td>
                        </tr>
                    @endforeach
                    @if($sommaire['sans_caisse']['tronque'])
                        <tr><td colspan="8" class="vide">Liste limitée aux premiers encaissements de la période.</td></tr>
                    @endif
                </tbody>
            </table>
        @endif
    </div>
    <div class="footer">{{ $entreprise['nom'] }} — sommaire de caisse {{ $caisse['code'] ?? '' }} — imprimé le {{ $genere_le->format('d/m/Y H:i') }}</div>
</body>
</html>
