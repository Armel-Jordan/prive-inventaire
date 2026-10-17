<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Certificat cadeau {{ $carte->numero }}</title>
    <style>
        @page { margin: 22px; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11px; color: #1f2937; }
        .cadre { border: 3px solid #059669; border-radius: 14px; padding: 34px 32px; }
        .entreprise { font-size: 15px; font-weight: bold; color: #065f46; }
        .coordonnees { font-size: 9px; color: #4b5563; }
        h1 { font-size: 30px; color: #059669; text-align: center; margin: 28px 0 8px; letter-spacing: 1px; }
        .sous-titre { text-align: center; color: #4b5563; margin-bottom: 24px; }
        .numero { text-align: center; font-family: 'DejaVu Sans Mono', monospace; font-size: 26px; font-weight: bold; letter-spacing: 3px; border: 1px dashed #059669; border-radius: 8px; padding: 12px; margin: 0 60px 26px; }
        .montants { width: 100%; border-collapse: collapse; }
        .montants td { width: 33%; text-align: center; vertical-align: top; padding: 4px; }
        .libelle { font-size: 9px; text-transform: uppercase; color: #6b7280; }
        .valeur { font-size: 16px; font-weight: bold; }
        .pied { margin-top: 30px; text-align: center; font-size: 9px; color: #4b5563; }
    </style>
</head>
<body>
    @php
        $montant = fn ($valeur) => number_format((float) $valeur, 2, ',', ' ').' '.$symbole;
    @endphp
    <div class="cadre">
        <div class="entreprise">{{ $entreprise['nom'] }}</div>
        <div class="coordonnees">{{ collect([$entreprise['adresse'], $entreprise['telephone'], $entreprise['email']])->filter()->implode(' — ') }}</div>

        <h1>Certificat cadeau</h1>
        <div class="sous-titre">Carte au porteur, valable en paiement de vos achats</div>
        <div class="numero">{{ $carte->numero }}</div>

        <table class="montants">
            <tr>
                <td><div class="libelle">Montant</div><div class="valeur">{{ $montant($carte->montant_initial) }}</div></td>
                <td><div class="libelle">Solde disponible</div><div class="valeur">{{ $montant($carte->solde) }}</div></td>
                <td>
                    <div class="libelle">Validité</div>
                    <div class="valeur">{{ $carte->expire_le ? "Jusqu'au ".$carte->expire_le->format('d/m/Y') : "Sans date d'expiration" }}</div>
                </td>
            </tr>
        </table>

        <div class="pied">
            Vendue le {{ $carte->date_vente->format('d/m/Y') }}. Présentez ce numéro au moment de payer : le montant de vos achats est déduit du solde de la carte.
            @if($carte->annulee)<br><strong>Cette carte est annulée.</strong>@endif
        </div>
    </div>
</body>
</html>
