<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Contrat de location {{ $contrat->numero }}</title>
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
        .parties { display: table; width: 100%; margin-bottom: 12px; }
        .partie { display: table-cell; width: 50%; vertical-align: top; padding: 8px; border: 1px solid #e5e7eb; }
        .label { font-size: 8px; color: #6b7280; text-transform: uppercase; margin-bottom: 2px; }
        .valeur { font-size: 11px; font-weight: bold; }
        .conditions { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .conditions td { padding: 5px 8px; border: 1px solid #e5e7eb; width: 25%; vertical-align: top; }
        .lignes { width: 100%; border-collapse: collapse; }
        .lignes th { background-color: #4f46e5; color: white; padding: 6px 5px; text-align: left; font-size: 8px; text-transform: uppercase; }
        .lignes td { padding: 6px 5px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .right { text-align: right; }
        .petit { font-size: 8px; color: #6b7280; }
        .totaux { margin-top: 10px; margin-left: auto; width: 50%; border-collapse: collapse; }
        .totaux td { padding: 4px 6px; }
        .totaux .fort { font-weight: bold; border-top: 1px solid #333; }
        .notes { margin-top: 12px; font-size: 9px; }
        .signatures { display: table; width: 100%; margin-top: 30px; }
        .signature { display: table-cell; width: 50%; padding-right: 20px; font-size: 9px; }
        .ligne-signature { border-bottom: 1px solid #333; height: 40px; margin-top: 4px; }
        .footer { position: fixed; bottom: 14px; left: 22px; right: 22px; text-align: center; font-size: 8px; color: #9ca3af; }
    </style>
</head>
<body>
    @php
        $montant = fn ($valeur) => number_format((float) $valeur, 2, ',', ' ').' '.$devise;
    @endphp
    <div class="container">
        <div class="header">
            <div class="header-left">
                <div class="company-name">{{ $entreprise['nom'] }}</div>
                <div class="company-info">{{ implode(' — ', array_filter([$entreprise['adresse'], $entreprise['telephone']])) }}</div>
            </div>
            <div class="header-right document-title">
                <h1>Contrat de location</h1>
                <div class="document-number">{{ $contrat->numero }}</div>
            </div>
        </div>

        <div class="parties">
            <div class="partie">
                <div class="label">Loueur</div>
                <div class="valeur">{{ $entreprise['nom'] }}</div>
            </div>
            <div class="partie">
                <div class="label">Client</div>
                <div class="valeur">{{ $contrat->client?->raison_sociale }}</div>
                <div class="petit">{{ implode(', ', array_filter([$contrat->client?->adresse_facturation, $contrat->client?->ville])) }}</div>
            </div>
        </div>

        <table class="conditions">
            <tr>
                <td><div class="label">Du</div>{{ $contrat->date_debut?->format('d/m/Y') }}</td>
                <td><div class="label">Au</div>{{ $contrat->date_fin?->format('d/m/Y') }}</td>
                <td><div class="label">Loyer</div>Par {{ $frequence }}, {{ $contrat->a_echoir ? "facturé à l'avance" : 'facturé après coup' }}, le {{ $contrat->jour_facturation }}</td>
                <td><div class="label">Type</div>{{ $contrat->type ? $contrat->type->code.' — '.$contrat->type->nom : '—' }}</td>
            </tr>
        </table>

        <table class="lignes">
            <thead>
                <tr>
                    <th style="width: 40%;">Produit</th>
                    <th class="right" style="width: 10%;">Quantité</th>
                    <th class="right" style="width: 10%;">Exemptée</th>
                    <th class="right" style="width: 20%;">Prix de contrat</th>
                    <th class="right" style="width: 20%;">Loyer par {{ $frequence }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($contrat->lignes as $ligne)
                    <tr>
                        <td>{{ $ligne->produit?->description }}<div class="petit">{{ $ligne->produit?->numero }}@if($ligne->quantite_retournee) — {{ $ligne->quantite_retournee }} rendue(s) le {{ $ligne->date_retour?->format('d/m/Y') }}@endif</div></td>
                        <td class="right">{{ $ligne->quantite }}</td>
                        <td class="right">{{ $ligne->quantite_exemptee ?: '' }}</td>
                        <td class="right">{{ $montant($ligne->prix_contrat) }}</td>
                        <td class="right">{{ $montant($ligne->prix_location) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totaux">
            <tr><td>Prix de contrat (une fois)</td><td class="right">{{ $montant($total_contrat) }}</td></tr>
            <tr><td class="fort">Loyer par {{ $frequence }}</td><td class="right fort">{{ $montant($loyer) }}</td></tr>
        </table>
        <p class="petit" style="margin-top: 4px; text-align: right;">Montants hors taxes ; les taxes s'ajoutent sur chaque facture.</p>

        @if($contrat->renouvellement_auto)
            @php
                $hausse = (float) $contrat->taux_contrat || (float) $contrat->taux_location
                    ? ', prix augmentés de '.(float) $contrat->taux_contrat.' % (contrat) et '.(float) $contrat->taux_location.' % (loyer)'
                    : '';
            @endphp
            <p class="notes">Renouvellement automatique à la fin du contrat, pour la même durée{{ $hausse }}.</p>
        @endif
        @if($contrat->notes)
            <p class="notes">{{ $contrat->notes }}</p>
        @endif

        <div class="signatures">
            <div class="signature">Pour le loueur (nom, date, signature)<div class="ligne-signature"></div></div>
            <div class="signature">Pour le client (nom, date, signature)<div class="ligne-signature"></div></div>
        </div>
    </div>

    <div class="footer">{{ $entreprise['nom'] }} — contrat de location {{ $contrat->numero }}</div>
</body>
</html>
