<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Bon de dépôt {{ $consignation->numero }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10px; line-height: 1.4; color: #333; }
        .container { padding: 22px; }
        .header { display: table; width: 100%; margin-bottom: 14px; }
        .header-left, .header-right { display: table-cell; vertical-align: top; width: 50%; }
        .company-name { font-size: 18px; font-weight: bold; color: #4f46e5; margin-bottom: 3px; }
        .company-info { font-size: 9px; color: #666; }
        .document-title { text-align: right; }
        .document-title h1 { font-size: 18px; color: #1f2937; text-transform: uppercase; }
        .document-number { font-size: 13px; font-weight: bold; color: #4f46e5; }
        .bloc { border: 1px solid #e5e7eb; padding: 8px; margin-bottom: 12px; }
        .label { font-size: 8px; color: #6b7280; text-transform: uppercase; margin-bottom: 2px; }
        .valeur { font-size: 11px; font-weight: bold; }
        .avis { font-size: 9px; color: #92400e; margin-bottom: 10px; }
        .lignes { width: 100%; border-collapse: collapse; }
        .lignes th { background-color: #4f46e5; color: white; padding: 6px 5px; text-align: left; font-size: 8px; text-transform: uppercase; }
        .lignes td { padding: 6px 5px; border-bottom: 1px solid #e5e7eb; }
        .right { text-align: right; }
        .petit { font-size: 8px; color: #6b7280; }
        .signatures { display: table; width: 100%; margin-top: 30px; }
        .signature { display: table-cell; width: 50%; padding-right: 20px; font-size: 9px; }
        .ligne-signature { border-bottom: 1px solid #333; height: 40px; margin-top: 4px; }
        .footer { position: fixed; bottom: 14px; left: 22px; right: 22px; text-align: center; font-size: 8px; color: #9ca3af; }
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
                <h1>Bon de dépôt</h1>
                <div class="document-number">{{ $consignation->numero }} — {{ $consignation->date_depot?->format('d/m/Y') }}</div>
            </div>
        </div>

        <div class="bloc">
            <div class="label">Client dépositaire</div>
            <div class="valeur">{{ $consignation->client?->raison_sociale }}</div>
            <div class="petit">{{ implode(', ', array_filter([$consignation->client?->adresse_facturation, $consignation->client?->ville])) }}</div>
        </div>

        <p class="avis">Marchandise déposée en consignation : elle reste la propriété de {{ $entreprise['nom'] }} jusqu'à sa facturation. Le client la conserve en bon état et la rend sur demande.</p>

        <table class="lignes">
            <thead>
                <tr>
                    <th style="width: 20%;">Produit</th>
                    <th style="width: 65%;">Désignation</th>
                    <th class="right" style="width: 15%;">Quantité</th>
                </tr>
            </thead>
            <tbody>
                @foreach($consignation->lignes as $ligne)
                    <tr>
                        <td>{{ $ligne->produit?->numero }}</td>
                        <td>{{ $ligne->produit?->description }}</td>
                        <td class="right">{{ $ligne->quantite }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="petit" style="margin-top: 6px; text-align: right;">{{ $consignation->lignes->sum('quantite') }} unité(s) déposée(s)</p>

        @if($consignation->notes)
            <p style="margin-top: 10px; font-size: 9px;">{{ $consignation->notes }}</p>
        @endif

        <div class="signatures">
            <div class="signature">Remis par (nom, date, signature)<div class="ligne-signature"></div></div>
            <div class="signature">Reçu par le client (nom, date, signature)<div class="ligne-signature"></div></div>
        </div>
    </div>

    <div class="footer">{{ $entreprise['nom'] }} — bon de dépôt {{ $consignation->numero }}</div>
</body>
</html>
