<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Reçu {{ $facture->numero }}</title>
    <style>
        @page { margin: 10px 9px; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 8px; line-height: 1.35; color: #111; }
        .centre { text-align: center; }
        .entreprise { font-size: 11px; font-weight: bold; }
        .petit { font-size: 7px; color: #444; }
        .titre { font-size: 10px; font-weight: bold; margin-top: 6px; }
        .trait { border-top: 1px dashed #555; margin: 5px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 1px 0; }
        .droite { text-align: right; white-space: nowrap; }
        .produit { font-weight: bold; }
        .total td { font-size: 10px; font-weight: bold; padding-top: 3px; }
        .merci { margin-top: 8px; }
    </style>
</head>
<body>
    @php
        $montant = fn ($valeur) => number_format((float) $valeur, 2, ',', ' ').' '.$symbole;
        $quantite = fn ($valeur) => rtrim(rtrim(number_format((float) $valeur, 3, ',', ' '), '0'), ',');
    @endphp
    <div class="centre">
        <div class="entreprise">{{ $entreprise['nom'] }}</div>
        @if($entreprise['adresse'])<div class="petit">{{ $entreprise['adresse'] }}</div>@endif
        @if($entreprise['telephone'])<div class="petit">{{ $entreprise['telephone'] }}</div>@endif
        @if($entreprise['mentions'])<div class="petit">{{ $entreprise['mentions'] }}</div>@endif
        <div class="titre">Reçu — {{ $facture->numero }}</div>
        <div class="petit">{{ $instant ? $instant->format('d/m/Y H:i') : $facture->date_facture?->format('d/m/Y') }}</div>
        @if($caisse || $vendeur)
            <div class="petit">{{ trim(($caisse ? 'Caisse '.$caisse->code : '').($caisse && $vendeur ? ' — ' : '').($vendeur ? 'Servi par '.$vendeur : '')) }}</div>
        @endif
        @if($client)<div class="petit">Client : {{ $client }}</div>@endif
    </div>
    <div class="trait"></div>

    <table>
        @foreach($facture->lignes as $ligne)
            <tr><td colspan="2" class="produit">{{ $ligne->produit?->description ?? 'Produit #'.$ligne->produit_id }}</td></tr>
            <tr>
                <td>{{ $quantite($ligne->quantite) }} × {{ $montant($ligne->prix_unitaire_ht) }}{{ (float) $ligne->remise_ligne > 0 ? ' (−'.$quantite($ligne->remise_ligne).' %)' : '' }}</td>
                <td class="droite">{{ $montant($ligne->montant_ht) }}</td>
            </tr>
        @endforeach
    </table>
    <div class="trait"></div>

    <table>
        @foreach($totaux as $total)
            <tr class="{{ !empty($total[2]) ? 'total' : '' }}"><td>{{ $total[0] }}</td><td class="droite">{{ $montant($total[1]) }}</td></tr>
        @endforeach
    </table>
    <div class="trait"></div>

    <table>
        @foreach($paiements as $paiement)
            <tr>
                <td>{{ $paiement->acompte_id ? 'Acompte' : ($paiement->carte_cadeau_id ? 'Carte cadeau' : ($modes[$paiement->mode_paiement] ?? $paiement->mode_paiement)) }}{{ $paiement->reference && ! $paiement->acompte_id ? ' '.$paiement->reference : '' }}</td>
                <td class="droite">{{ $montant($paiement->montant) }}</td>
            </tr>
            @if($paiement->carte_cadeau_id && isset($soldesCartes[$paiement->id]))
                <tr><td class="petit">Solde restant sur la carte</td><td class="droite petit">{{ $montant($soldesCartes[$paiement->id]) }}</td></tr>
            @endif
            @if($paiement->montant_recu !== null && (float) $paiement->montant_recu > (float) $paiement->montant)
                <tr><td class="petit">Argent remis</td><td class="droite petit">{{ $montant($paiement->montant_recu) }}</td></tr>
                <tr><td>Monnaie rendue</td><td class="droite">{{ $montant((float) $paiement->montant_recu - (float) $paiement->montant) }}</td></tr>
            @endif
        @endforeach
        @if((float) $facture->reste_a_payer > 0.004)
            <tr class="total"><td>Reste dû</td><td class="droite">{{ $montant($facture->reste_a_payer) }}</td></tr>
        @endif
    </table>

    <div class="centre merci">Merci de votre visite</div>
</body>
</html>
