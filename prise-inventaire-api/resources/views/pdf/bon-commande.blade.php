<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bon de Commande {{ $commande->numero }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
        }
        .container {
            padding: 20px;
        }
        .header {
            display: table;
            width: 100%;
            margin-bottom: 30px;
        }
        .header-left, .header-right {
            display: table-cell;
            vertical-align: top;
            width: 50%;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #2563eb;
            margin-bottom: 5px;
        }
        .company-info {
            font-size: 10px;
            color: #666;
        }
        .document-title {
            text-align: right;
        }
        .document-title h1 {
            font-size: 28px;
            color: #1f2937;
            margin-bottom: 5px;
        }
        .document-number {
            font-size: 16px;
            font-weight: bold;
            color: #2563eb;
        }
        .info-section {
            display: table;
            width: 100%;
            margin-bottom: 25px;
        }
        .info-box {
            display: table-cell;
            width: 48%;
            vertical-align: top;
        }
        .info-box-spacer {
            display: table-cell;
            width: 4%;
        }
        .info-box-title {
            background-color: #2563eb;
            color: white;
            padding: 8px 12px;
            font-weight: bold;
            font-size: 11px;
            text-transform: uppercase;
        }
        .info-box-content {
            border: 1px solid #e5e7eb;
            border-top: none;
            padding: 12px;
            min-height: 100px;
        }
        .info-label {
            font-size: 10px;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 2px;
        }
        .info-value {
            font-size: 12px;
            margin-bottom: 8px;
        }
        .dates-section {
            margin-bottom: 25px;
        }
        .dates-table {
            width: 100%;
            border-collapse: collapse;
        }
        .dates-table td {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
        }
        .dates-table .label {
            background-color: #f3f4f6;
            font-weight: bold;
            width: 25%;
        }
        .products-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .products-table th {
            background-color: #2563eb;
            color: white;
            padding: 10px 8px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
        }
        .products-table th.right {
            text-align: right;
        }
        .products-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #e5e7eb;
        }
        .products-table td.right {
            text-align: right;
        }
        .products-table tr:nth-child(even) {
            background-color: #f9fafb;
        }
        .totals-section {
            width: 300px;
            margin-left: auto;
            margin-bottom: 30px;
        }
        .totals-table {
            width: 100%;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
        }
        .totals-table .label {
            background-color: #f3f4f6;
            font-weight: bold;
        }
        .totals-table .total-row {
            background-color: #2563eb;
            color: white;
            font-size: 14px;
        }
        .totals-table .total-row td {
            border-color: #2563eb;
        }
        .notes-section {
            margin-bottom: 30px;
        }
        .notes-title {
            font-weight: bold;
            margin-bottom: 5px;
            color: #374151;
        }
        .notes-content {
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            padding: 12px;
            min-height: 60px;
        }
        .signature-section {
            display: table;
            width: 100%;
            margin-top: 40px;
        }
        .signature-box {
            display: table-cell;
            width: 45%;
            text-align: center;
        }
        .signature-spacer {
            display: table-cell;
            width: 10%;
        }
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 60px;
            padding-top: 5px;
            font-size: 10px;
        }
        .footer {
            position: fixed;
            bottom: 20px;
            left: 20px;
            right: 20px;
            text-align: center;
            font-size: 9px;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
            padding-top: 10px;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-brouillon { background-color: #e5e7eb; color: #374151; }
        .status-envoyee { background-color: #dbeafe; color: #1d4ed8; }
        .status-partielle { background-color: #fef3c7; color: #b45309; }
        .status-complete { background-color: #d1fae5; color: #047857; }
        .status-annulee { background-color: #fee2e2; color: #b91c1c; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-left">
                <div class="company-name">Prise Inventaire</div>
                <div class="company-info">
                    Système de gestion d'inventaire<br>
                    contact@prise-inventaire.com
                </div>
            </div>
            <div class="header-right document-title">
                <h1>BON DE COMMANDE</h1>
                <div class="document-number">{{ $commande->numero }}</div>
                <div style="margin-top: 10px;">
                    <span class="status-badge status-{{ $commande->statut }}">
                        @switch($commande->statut)
                            @case('brouillon') Brouillon @break
                            @case('envoyee') Envoyée @break
                            @case('partielle') Partielle @break
                            @case('complete') Complète @break
                            @case('annulee') Annulée @break
                        @endswitch
                    </span>
                </div>
            </div>
        </div>

        <div class="info-section">
            <div class="info-box">
                <div class="info-box-title">Fournisseur</div>
                <div class="info-box-content">
                    <div class="info-value" style="font-weight: bold; font-size: 14px;">
                        {{ $commande->fournisseur->raison_sociale }}
                    </div>
                    @if($commande->fournisseur->adresse)
                        <div class="info-value">{{ $commande->fournisseur->adresse }}</div>
                    @endif
                    @if($commande->fournisseur->telephone)
                        <div class="info-label">Téléphone</div>
                        <div class="info-value">{{ $commande->fournisseur->telephone }}</div>
                    @endif
                    @if($commande->fournisseur->email)
                        <div class="info-label">Email</div>
                        <div class="info-value">{{ $commande->fournisseur->email }}</div>
                    @endif
                </div>
            </div>
            <div class="info-box-spacer"></div>
            <div class="info-box">
                <div class="info-box-title">Informations commande</div>
                <div class="info-box-content">
                    <div class="info-label">Date de commande</div>
                    <div class="info-value">{{ \Carbon\Carbon::parse($commande->date_commande)->format('d/m/Y') }}</div>

                    <div class="info-label">Date de livraison souhaitée</div>
                    <div class="info-value">
                        {{ $commande->date_livraison_prevue ? \Carbon\Carbon::parse($commande->date_livraison_prevue)->format('d/m/Y') : 'Non spécifiée' }}
                    </div>

                    @if($commande->fournisseur->conditions_paiement)
                        <div class="info-label">Conditions de paiement</div>
                        <div class="info-value">{{ $commande->fournisseur->conditions_paiement }}</div>
                    @endif
                </div>
            </div>
        </div>

        <table class="products-table">
            <thead>
                <tr>
                    <th style="width: 15%;">Référence</th>
                    <th style="width: 40%;">Désignation</th>
                    <th class="right" style="width: 15%;">Quantité</th>
                    <th class="right" style="width: 15%;">Prix unitaire</th>
                    <th class="right" style="width: 15%;">Montant</th>
                </tr>
            </thead>
            <tbody>
                @foreach($commande->lignes as $ligne)
                <tr>
                    <td>{{ $ligne->produit->numero ?? '-' }}</td>
                    <td>{{ $ligne->produit->description ?? 'Produit #'.$ligne->produit_id }}</td>
                    <td class="right">{{ number_format($ligne->quantite_commandee, 0, ',', ' ') }}</td>
                    <td class="right">{{ number_format($ligne->prix_unitaire, 2, ',', ' ') }} €</td>
                    <td class="right">{{ number_format($ligne->montant_ligne, 2, ',', ' ') }} €</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals-section">
            <table class="totals-table">
                <tr>
                    <td class="label">Sous-total HT</td>
                    <td class="right">{{ number_format($commande->montant_total, 2, ',', ' ') }} €</td>
                </tr>
                <tr>
                    <td class="label">TVA (20%)</td>
                    <td class="right">{{ number_format($commande->montant_total * 0.20, 2, ',', ' ') }} €</td>
                </tr>
                <tr class="total-row">
                    <td><strong>TOTAL TTC</strong></td>
                    <td class="right"><strong>{{ number_format($commande->montant_total * 1.20, 2, ',', ' ') }} €</strong></td>
                </tr>
            </table>
        </div>

        @if($commande->notes)
        <div class="notes-section">
            <div class="notes-title">Notes / Instructions</div>
            <div class="notes-content">{{ $commande->notes }}</div>
        </div>
        @endif

        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line">
                    Signature de l'acheteur<br>
                    {{ $commande->createdBy->nom ?? '' }}
                </div>
            </div>
            <div class="signature-spacer"></div>
            <div class="signature-box">
                <div class="signature-line">
                    Cachet et signature du fournisseur
                </div>
            </div>
        </div>
    </div>

    <div class="footer">
        Document généré le {{ now()->format('d/m/Y à H:i') }} | Prise Inventaire - Système de gestion d'inventaire
    </div>
</body>
</html>
