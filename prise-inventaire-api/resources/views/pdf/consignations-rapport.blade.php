<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Consignations chez les clients</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10px; line-height: 1.35; color: #333; }
        .container { padding: 18px; }
        .header { display: table; width: 100%; margin-bottom: 8px; }
        .header-left, .header-right { display: table-cell; vertical-align: top; width: 50%; }
        .company-name { font-size: 16px; font-weight: bold; color: #4f46e5; }
        .document-title { text-align: right; }
        .document-title h1 { font-size: 16px; color: #1f2937; text-transform: uppercase; }
        .filtre { font-size: 9px; color: #6b7280; margin-bottom: 10px; }
        .lignes { width: 100%; border-collapse: collapse; }
        .lignes th { background-color: #4f46e5; color: white; padding: 5px; text-align: left; font-size: 8px; text-transform: uppercase; }
        .lignes td { padding: 4px 5px; border-bottom: 1px solid #f3f4f6; }
        .client td { background: #eef2ff; font-weight: bold; border-bottom: 1px solid #c7d2fe; }
        .sous-total td { font-weight: bold; border-bottom: 1px solid #9ca3af; }
        .total td { font-weight: bold; font-size: 11px; border-top: 2px solid #333; }
        .right { text-align: right; }
        .footer { position: fixed; bottom: 12px; left: 18px; right: 18px; text-align: center; font-size: 8px; color: #9ca3af; }
    </style>
</head>
<body>
    @php
        $montant = fn ($valeur) => $valeur === null ? '—' : number_format((float) $valeur, 2, ',', ' ').' '.$rapport['devise'];
    @endphp
    <div class="container">
        <div class="header">
            <div class="header-left"><div class="company-name">{{ $entreprise['nom'] }}</div></div>
            <div class="header-right document-title"><h1>Consignations chez les clients</h1><div>{{ $genere_le->format('d/m/Y H:i') }}</div></div>
        </div>
        <p class="filtre">{{ $rapport['filtre'] }}</p>

        <table class="lignes">
            <thead>
                <tr>
                    <th style="width: 14%;">Produit</th>
                    <th style="width: 40%;">Désignation</th>
                    <th class="right" style="width: 10%;">Qté consignée</th>
                    <th class="right" style="width: 12%;">Coûtant</th>
                    <th class="right" style="width: 12%;">Valeur</th>
                    <th style="width: 12%;">Dernier dépôt</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rapport['clients'] as $bloc)
                    <tr class="client"><td colspan="6">{{ $bloc['client']['code'] ?? '' }} - {{ $bloc['client']['raison_sociale'] ?? '' }}</td></tr>
                    @foreach($bloc['lignes'] as $ligne)
                        <tr>
                            <td>{{ $ligne['produit']['numero'] ?? '' }}</td>
                            <td>{{ $ligne['produit']['description'] ?? '' }}</td>
                            <td class="right">{{ $ligne['quantite'] }}</td>
                            <td class="right">{{ $montant($ligne['cout_unitaire']) }}</td>
                            <td class="right">{{ $montant($ligne['valeur']) }}</td>
                            <td>{{ $ligne['dernier_depot'] ? \Carbon\Carbon::parse($ligne['dernier_depot'])->format('d/m/Y') : '' }}</td>
                        </tr>
                    @endforeach
                    <tr class="sous-total"><td colspan="2">Sous-total du client</td><td class="right">{{ $bloc['quantite'] }}</td><td></td><td class="right">{{ $montant($bloc['valeur']) }}</td><td></td></tr>
                @empty
                    <tr><td colspan="6">Aucune marchandise en consignation pour ces critères.</td></tr>
                @endforelse
                @if($rapport['clients'])
                    <tr class="total"><td colspan="2">Total général</td><td class="right">{{ $rapport['quantite'] }}</td><td></td><td class="right">{{ $montant($rapport['valeur']) }}</td><td></td></tr>
                @endif
            </tbody>
        </table>
    </div>
    <div class="footer">{{ $entreprise['nom'] }} — consignations chez les clients — {{ $genere_le->format('d/m/Y H:i') }}</div>
</body>
</html>
