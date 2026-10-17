@php
    $nombre = fn ($valeur) => number_format((float) $valeur, 2, ',', ' ');
    $modes = ['especes' => 'Espèces', 'cheque' => 'Chèque', 'virement' => 'Virement', 'carte' => 'Carte', 'autre' => 'Autre'];
    $client = $encaissement->client;
    $annule = $encaissement->statut === 'annule';
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Reçu {{ $encaissement->numero }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11px; line-height: 1.4; color: #333; }
        .container { padding: 20px; }
        .header { display: table; width: 100%; margin-bottom: 25px; }
        .header-left, .header-right { display: table-cell; vertical-align: top; width: 50%; }
        .company-name { font-size: 22px; font-weight: bold; color: #0d9488; margin-bottom: 5px; }
        .company-info { font-size: 10px; color: #666; }
        .document-title { text-align: right; }
        .document-title h1 { font-size: 24px; color: #1f2937; margin-bottom: 5px; }
        .document-number { font-size: 16px; font-weight: bold; color: #0d9488; }
        .annule { margin-top: 8px; font-size: 13px; font-weight: bold; color: #b91c1c; border: 2px solid #b91c1c; display: inline-block; padding: 3px 10px; }
        .info-section { display: table; width: 100%; margin-bottom: 20px; }
        .info-box { display: table-cell; width: 48%; vertical-align: top; }
        .info-box-spacer { display: table-cell; width: 4%; }
        .info-box-title { background-color: #0d9488; color: white; padding: 7px 12px; font-weight: bold; font-size: 10px; text-transform: uppercase; }
        .info-box-content { border: 1px solid #e5e7eb; border-top: none; padding: 10px 12px; min-height: 80px; }
        .info-label { font-size: 9px; color: #6b7280; text-transform: uppercase; margin-bottom: 1px; }
        .info-value { font-size: 11px; margin-bottom: 6px; }
        .products-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .products-table th { background-color: #0d9488; color: white; padding: 8px 6px; text-align: left; font-size: 9px; text-transform: uppercase; }
        .products-table th.right, .products-table td.right { text-align: right; }
        .products-table td { padding: 7px 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .products-table tr:nth-child(even) { background-color: #f9fafb; }
        .totals-section { width: 300px; margin-left: auto; margin-bottom: 25px; }
        .totals-table { width: 100%; border-collapse: collapse; }
        .totals-table td { padding: 7px 10px; border: 1px solid #e5e7eb; }
        .totals-table .total-row { background-color: #0d9488; color: white; font-size: 12px; }
        .notes-section { margin-bottom: 25px; }
        .notes-title { font-weight: bold; margin-bottom: 5px; color: #374151; }
        .notes-content { background-color: #f9fafb; border: 1px solid #e5e7eb; padding: 10px; }
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
                <h1>REÇU</h1>
                <div class="document-number">{{ $encaissement->numero }}</div>
                @if($annule)
                    <div class="annule">ANNULÉ</div>
                @endif
            </div>
        </div>

        <div class="info-section">
            <div class="info-box">
                <div class="info-box-title">Reçu de</div>
                <div class="info-box-content">
                    <div class="info-value" style="font-weight: bold; font-size: 13px;">{{ $client->raison_sociale }}</div>
                    @if($client->adresse_facturation)
                        <div class="info-value">{{ $client->adresse_facturation }}@if($client->ville), {{ $client->code_postal }} {{ $client->ville }}@endif</div>
                    @endif
                    @if($client->code)
                        <div class="info-label">N° de client</div>
                        <div class="info-value">{{ $client->code }}</div>
                    @endif
                </div>
            </div>
            <div class="info-box-spacer"></div>
            <div class="info-box">
                <div class="info-box-title">Paiement</div>
                <div class="info-box-content">
                    <div class="info-label">Date</div>
                    <div class="info-value">{{ $encaissement->date_encaissement->format('d/m/Y') }}</div>
                    <div class="info-label">Mode de paiement</div>
                    <div class="info-value">{{ $modes[$encaissement->mode] ?? $encaissement->mode }}@if($encaissement->reference) — {{ $encaissement->reference }}@endif</div>
                    @if($annule)
                        <div class="info-label">Annulé le</div>
                        <div class="info-value">{{ $encaissement->annule_le?->format('d/m/Y') }}@if($encaissement->auteurAnnulation) par {{ $encaissement->auteurAnnulation->nom }}@endif @if($encaissement->motif_annulation)— {{ $encaissement->motif_annulation }}@endif</div>
                    @endif
                </div>
            </div>
        </div>

        @if($encaissement->lignes->isNotEmpty())
            <table class="products-table">
                <thead>
                    <tr>
                        <th style="width: 25%;">Facture</th>
                        <th style="width: 20%;">Date</th>
                        <th class="right" style="width: 20%;">Montant de la facture</th>
                        <th class="right" style="width: 15%;">Escompte</th>
                        <th class="right" style="width: 20%;">Montant réglé</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($encaissement->lignes as $ligne)
                        <tr>
                            <td>{{ $ligne->facture?->numero }}</td>
                            <td>{{ $ligne->facture?->date_facture?->format('d/m/Y') }}</td>
                            <td class="right">{{ $nombre($ligne->facture?->montant_ttc) }} {{ $symbole }}</td>
                            <td class="right">{{ (float) $ligne->escompte > 0 ? $nombre($ligne->escompte).' '.$symbole : '-' }}</td>
                            <td class="right">{{ $nombre($ligne->montant) }} {{ $symbole }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div class="totals-section">
            <table class="totals-table">
                @if($encaissement->lignes->isNotEmpty())
                    <tr>
                        <td>Factures réglées</td>
                        <td style="text-align: right;">{{ $nombre($encaissement->montant_applique) }} {{ $symbole }}</td>
                    </tr>
                @endif
                @if((float) $encaissement->escompte > 0)
                    <tr>
                        <td>Escompte accordé</td>
                        <td style="text-align: right;">- {{ $nombre($encaissement->escompte) }} {{ $symbole }}</td>
                    </tr>
                @endif
                @if((float) $encaissement->surplus > 0)
                    <tr>
                        <td>Laissé à votre crédit</td>
                        <td style="text-align: right;">{{ $nombre($encaissement->surplus) }} {{ $symbole }}</td>
                    </tr>
                @endif
                <tr class="total-row">
                    <td><strong>MONTANT REÇU</strong></td>
                    <td style="text-align: right;"><strong>{{ $nombre($encaissement->montant) }} {{ $symbole }}</strong></td>
                </tr>
            </table>
        </div>

        @if($encaissement->notes)
            <div class="notes-section">
                <div class="notes-title">Notes</div>
                <div class="notes-content">{{ $encaissement->notes }}</div>
            </div>
        @endif
    </div>
    <div class="footer">
        {{ $entreprise['nom'] }} — Reçu {{ $encaissement->numero }}@if($encaissement->auteur) — reçu par {{ $encaissement->auteur->nom }}@endif
    </div>
</body>
</html>
