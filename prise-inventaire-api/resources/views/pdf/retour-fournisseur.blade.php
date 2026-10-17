@php
    // Montants dans la devise du retour (celle de l'entreprise, ou celle du fournisseur).
    $montant = fn ($valeur) => number_format((float) $valeur, 2, ',', ' ').' '.$symbole;
    $types = ['remboursement' => 'Remboursement', 'remplacement' => 'Remplacement', 'consigne' => 'Contenant consigné'];
    $statuts = ['brouillon' => 'Brouillon', 'expedie' => 'Expédié', 'clos' => 'Clos'];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Bon de retour {{ $retour->numero ?? 'brouillon' }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 12px; line-height: 1.4; color: #333; }
        .container { padding: 20px; }
        .header { display: table; width: 100%; margin-bottom: 30px; }
        .header-left, .header-right { display: table-cell; vertical-align: top; width: 50%; }
        .company-name { font-size: 24px; font-weight: bold; color: #0d9488; margin-bottom: 5px; }
        .company-info { font-size: 10px; color: #666; }
        .document-title { text-align: right; }
        .document-title h1 { font-size: 26px; color: #1f2937; margin-bottom: 5px; }
        .document-number { font-size: 16px; font-weight: bold; color: #0d9488; }
        .rma { margin-top: 8px; font-size: 13px; font-weight: bold; border: 2px solid #1f2937; display: inline-block; padding: 4px 10px; }
        .info-section { display: table; width: 100%; margin-bottom: 25px; }
        .info-box { display: table-cell; width: 48%; vertical-align: top; }
        .info-box-spacer { display: table-cell; width: 4%; }
        .info-box-title { background-color: #0d9488; color: white; padding: 8px 12px; font-weight: bold; font-size: 11px; text-transform: uppercase; }
        .info-box-content { border: 1px solid #e5e7eb; border-top: none; padding: 12px; min-height: 90px; }
        .info-label { font-size: 10px; color: #6b7280; text-transform: uppercase; margin-bottom: 2px; }
        .info-value { font-size: 12px; margin-bottom: 8px; }
        .products-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .products-table th { background-color: #0d9488; color: white; padding: 10px 8px; text-align: left; font-size: 11px; text-transform: uppercase; }
        .products-table th.right, .products-table td.right { text-align: right; }
        .products-table td { padding: 10px 8px; border-bottom: 1px solid #e5e7eb; }
        .products-table tr:nth-child(even) { background-color: #f9fafb; }
        .reason { font-size: 10px; color: #6b7280; }
        .totals-section { width: 300px; margin-left: auto; margin-bottom: 30px; }
        .totals-table { width: 100%; border-collapse: collapse; }
        .totals-table td { padding: 8px 12px; border: 1px solid #e5e7eb; }
        .totals-table .total-row { background-color: #0d9488; color: white; font-size: 14px; }
        .totals-table .label { background-color: #f3f4f6; font-weight: bold; }
        .notes-section { margin-bottom: 30px; }
        .notes-title { font-weight: bold; margin-bottom: 5px; color: #374151; }
        .notes-content { background-color: #f9fafb; border: 1px solid #e5e7eb; padding: 12px; }
        .signature-section { display: table; width: 100%; margin-top: 40px; }
        .signature-box { display: table-cell; width: 45%; text-align: center; }
        .signature-spacer { display: table-cell; width: 10%; }
        .signature-line { border-top: 1px solid #333; margin-top: 60px; padding-top: 5px; font-size: 10px; }
        .footer { position: fixed; bottom: 20px; left: 20px; right: 20px; text-align: center; font-size: 9px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-left">
                <div class="company-name">{{ $entreprise['nom'] }}</div>
                <div class="company-info">
                    @foreach (array_filter([$entreprise['adresse'], $entreprise['telephone'], $entreprise['email'], $entreprise['mentions']]) as $coordonnee)
                        {{ $coordonnee }}@if (! $loop->last)<br>@endif
                    @endforeach
                </div>
            </div>
            <div class="header-right document-title">
                <h1>BON DE RETOUR</h1>
                <div class="document-number">{{ $retour->numero ?? 'Brouillon' }}</div>
                <div>{{ $types[$retour->type] ?? $retour->type }} · {{ $statuts[$retour->statut] ?? $retour->statut }}</div>
                @if($retour->numero_rma)
                    <div class="rma">RMA {{ $retour->numero_rma }}</div>
                @endif
            </div>
        </div>

        <div class="info-section">
            <div class="info-box">
                <div class="info-box-title">Fournisseur</div>
                <div class="info-box-content">
                    <div class="info-value" style="font-weight: bold; font-size: 14px;">{{ $retour->fournisseur->raison_sociale }}</div>
                    @if($retour->fournisseur->adresse)
                        <div class="info-value">{{ $retour->fournisseur->adresse }}@if($retour->fournisseur->ville), {{ $retour->fournisseur->ville }}@endif</div>
                    @endif
                    @if($retour->fournisseur->telephone)
                        <div class="info-label">Téléphone</div>
                        <div class="info-value">{{ $retour->fournisseur->telephone }}</div>
                    @endif
                    @if($retour->fournisseur->email)
                        <div class="info-label">Courriel</div>
                        <div class="info-value">{{ $retour->fournisseur->email }}</div>
                    @endif
                </div>
            </div>
            <div class="info-box-spacer"></div>
            <div class="info-box">
                <div class="info-box-title">Retour</div>
                <div class="info-box-content">
                    <div class="info-label">Date du retour</div>
                    <div class="info-value">{{ $retour->date_retour->format('d/m/Y') }}</div>
                    @if($retour->date_expedition)
                        <div class="info-label">Expédié le</div>
                        <div class="info-value">{{ $retour->date_expedition->format('d/m/Y') }}</div>
                    @endif
                    @if($retour->commande)
                        <div class="info-label">Commande d'origine</div>
                        <div class="info-value">{{ $retour->commande->numero }}</div>
                    @endif
                    @if($retour->devise)
                        <div class="info-label">Devise</div>
                        <div class="info-value">{{ $retour->devise }} (1 {{ $retour->devise }} = {{ rtrim(rtrim(number_format((float) $retour->taux_change, 6, ',', ' '), '0'), ',') }} {{ $reference }})</div>
                    @endif
                </div>
            </div>
        </div>

        <table class="products-table">
            <thead>
                <tr>
                    <th style="width: 15%;">Référence</th>
                    <th style="width: 40%;">Désignation</th>
                    <th class="right" style="width: 13%;">Quantité</th>
                    <th class="right" style="width: 16%;">Prix unitaire</th>
                    <th class="right" style="width: 16%;">Montant</th>
                </tr>
            </thead>
            <tbody>
                @foreach($retour->lignes as $ligne)
                <tr>
                    <td>{{ $ligne->produit->numero ?? '-' }}</td>
                    <td>
                        {{ $ligne->produit->description ?? 'Produit #'.$ligne->produit_id }}
                        @if($ligne->raison)<div class="reason">{{ $ligne->raison->libelle }}</div>@endif
                    </td>
                    <td class="right">{{ number_format($ligne->quantite, 0, ',', ' ') }}</td>
                    <td class="right">{{ $montant($ligne->prix_unitaire) }}</td>
                    <td class="right">{{ $montant($ligne->quantite * (float) $ligne->prix_unitaire) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals-section">
            <table class="totals-table">
                <tr class="total-row">
                    <td><strong>{{ $retour->type === 'remboursement' ? 'CRÉDIT ATTENDU HT' : 'VALEUR HT' }}</strong></td>
                    <td class="right"><strong>{{ $montant($retour->montant) }}</strong></td>
                </tr>
                @if($retour->montant_credite !== null)
                    <tr>
                        <td class="label">Crédit obtenu</td>
                        <td class="right">{{ $montant($retour->montant_credite) }}@if($retour->reference_avoir) ({{ $retour->reference_avoir }})@endif</td>
                    </tr>
                @endif
            </table>
        </div>

        @if($retour->notes)
        <div class="notes-section">
            <div class="notes-title">Notes</div>
            <div class="notes-content">{{ $retour->notes }}</div>
        </div>
        @endif

        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line">Expédié par<br>{{ $retour->auteur->nom ?? '' }}</div>
            </div>
            <div class="signature-spacer"></div>
            <div class="signature-box">
                <div class="signature-line">Reçu par le fournisseur</div>
            </div>
        </div>
    </div>

    <div class="footer">
        {{ $entreprise['nom'] }} — document généré le {{ now()->format('d/m/Y à H:i') }}
    </div>
</body>
</html>
