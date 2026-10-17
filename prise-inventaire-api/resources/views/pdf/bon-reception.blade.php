@php
    // Montants dans la devise de chaque commande (celle du fournisseur), sinon dans celle de l'entreprise.
    $symbole = fn ($ligne) => $ligne->ligneCommande?->commande?->devise ?: $devise;
    $nombre = fn ($valeur, $decimales = 2) => number_format((float) $valeur, $decimales, ',', ' ');
    $cout = fn ($ligne) => (float) ($ligne->cout_unitaire ?? $ligne->ligneCommande?->prix_unitaire ?? 0);
    // Un coût à plus de deux décimales (1,2345) s'imprime en entier.
    $decimales = fn ($valeur) => abs($valeur - round($valeur, 2)) < 0.00005 ? 2 : 4;
    $totaux = [];
    $valeurReference = 0.0;
    foreach ($reception->lignes as $ligne) {
        $totaux[$symbole($ligne)] = ($totaux[$symbole($ligne)] ?? 0) + $ligne->quantite_recue * $cout($ligne);
        $valeurReference += $ligne->quantite_recue * $cout($ligne) * ((float) $ligne->ligneCommande?->commande?->taux_change ?: 1);
    }
    // Coûts annexes (transport, douane…) : dans la devise de l'entreprise, ils s'ajoutent au coût de revient.
    $types = ['transport' => 'Transport', 'douane' => 'Douane', 'assurance' => 'Assurance', 'manutention' => 'Manutention', 'autre' => 'Autre'];
    $repartitions = ['valeur' => 'À la valeur', 'quantite' => 'À la quantité', 'poids' => 'Au poids', 'volume' => 'Au volume'];
    $fraisTotal = $reception->frais->sum(fn ($cout) => $cout->montantReference());
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Bon de réception {{ $reception->numero }}</title>
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
        .annulee { margin-top: 8px; font-size: 13px; font-weight: bold; color: #b91c1c; border: 2px solid #b91c1c; display: inline-block; padding: 3px 10px; }
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
        .detail { font-size: 9px; color: #6b7280; }
        .totals-section { width: 300px; margin-left: auto; margin-bottom: 25px; }
        .totals-table { width: 100%; border-collapse: collapse; }
        .totals-table td { padding: 7px 10px; border: 1px solid #e5e7eb; }
        .totals-table .total-row { background-color: #0d9488; color: white; font-size: 12px; }
        .notes-section { margin-bottom: 25px; }
        .notes-title { font-weight: bold; margin-bottom: 5px; color: #374151; }
        .notes-content { background-color: #f9fafb; border: 1px solid #e5e7eb; padding: 10px; }
        .signature-section { display: table; width: 100%; margin-top: 30px; }
        .signature-box { display: table-cell; width: 45%; text-align: center; }
        .signature-spacer { display: table-cell; width: 10%; }
        .signature-line { border-top: 1px solid #333; margin-top: 50px; padding-top: 5px; font-size: 10px; }
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
                <h1>BON DE RÉCEPTION</h1>
                <div class="document-number">{{ $reception->numero }}</div>
                @if($reception->statut === 'annulee')
                    <div class="annulee">ANNULÉE</div>
                @endif
            </div>
        </div>

        <div class="info-section">
            <div class="info-box">
                <div class="info-box-title">Fournisseur</div>
                <div class="info-box-content">
                    <div class="info-value" style="font-weight: bold; font-size: 13px;">{{ $reception->fournisseur->raison_sociale }}</div>
                    @if($reception->fournisseur->adresse)
                        <div class="info-value">{{ $reception->fournisseur->adresse }}@if($reception->fournisseur->ville), {{ $reception->fournisseur->ville }}@endif</div>
                    @endif
                    @if($reception->fournisseur->telephone)
                        <div class="info-label">Téléphone</div>
                        <div class="info-value">{{ $reception->fournisseur->telephone }}</div>
                    @endif
                </div>
            </div>
            <div class="info-box-spacer"></div>
            <div class="info-box">
                <div class="info-box-title">Réception</div>
                <div class="info-box-content">
                    <div class="info-label">Date de réception</div>
                    <div class="info-value">{{ $reception->date_reception->format('d/m/Y') }}</div>
                    @if($reception->bon_livraison)
                        <div class="info-label">Bon de livraison du fournisseur</div>
                        <div class="info-value">{{ $reception->bon_livraison }}</div>
                    @endif
                    @if($reception->connaissement)
                        <div class="info-label">Connaissement</div>
                        <div class="info-value">{{ $reception->connaissement }}</div>
                    @endif
                    @if($reception->statut === 'annulee')
                        <div class="info-label">Annulée le</div>
                        <div class="info-value">{{ $reception->annulee_le?->format('d/m/Y') }}@if($reception->auteurAnnulation) par {{ $reception->auteurAnnulation->nom }}@endif @if($reception->motif_annulation)— {{ $reception->motif_annulation }}@endif</div>
                    @endif
                </div>
            </div>
        </div>

        <table class="products-table">
            <thead>
                <tr>
                    <th style="width: 13%;">Commande</th>
                    <th style="width: 33%;">Produit</th>
                    <th style="width: 14%;">Secteur</th>
                    <th class="right" style="width: 12%;">Quantité</th>
                    <th class="right" style="width: 14%;">Coût unitaire</th>
                    <th class="right" style="width: 14%;">Montant</th>
                </tr>
            </thead>
            <tbody>
                @foreach($reception->lignes as $ligne)
                    @php($commande = $ligne->ligneCommande)
                    <tr>
                        <td>{{ $commande?->commande?->numero ?? '-' }}</td>
                        <td>
                            <strong>{{ $commande?->produit?->numero }}</strong> {{ $commande?->produit?->description }}
                            @if($ligne->lot_numero || $ligne->date_peremption)
                                <div class="detail">
                                    @if($ligne->lot_numero)Lot {{ $ligne->lot_numero }}@endif
                                    @if($ligne->lot_numero && $ligne->date_peremption) · @endif
                                    @if($ligne->date_peremption)Péremption {{ $ligne->date_peremption->format('d/m/Y') }}@endif
                                </div>
                            @endif
                            @if($ligne->notes)<div class="detail">{{ $ligne->notes }}</div>@endif
                        </td>
                        <td>{{ $ligne->secteur ? $ligne->secteur->code.' '.$ligne->secteur->nom : '-' }}</td>
                        <td class="right">
                            {{ $nombre($ligne->quantite_recue, 0) }} {{ $commande?->unite_achat }}
                            @if(($commande?->qte_par_unite_achat ?? 1) > 1)
                                <div class="detail">= {{ $nombre($ligne->quantite_recue * $commande->qte_par_unite_achat, 0) }} {{ $commande->produit?->mesure ?? 'UN' }}</div>
                            @endif
                        </td>
                        <td class="right">{{ $nombre($cout($ligne), $decimales($cout($ligne))) }} {{ $symbole($ligne) }}</td>
                        <td class="right">{{ $nombre($ligne->quantite_recue * $cout($ligne)) }} {{ $symbole($ligne) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if($reception->frais->isNotEmpty())
            <table class="products-table">
                <thead>
                    <tr>
                        <th style="width: 40%;">Coût annexe</th>
                        <th style="width: 25%;">Facturé par</th>
                        <th style="width: 15%;">Répartition</th>
                        <th class="right" style="width: 20%;">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($reception->frais as $coutAnnexe)
                        <tr>
                            <td>
                                {{ $types[$coutAnnexe->type] ?? $coutAnnexe->type }}@if($coutAnnexe->libelle) — {{ $coutAnnexe->libelle }}@endif
                                @if($coutAnnexe->reference)<div class="detail">Réf. {{ $coutAnnexe->reference }}</div>@endif
                            </td>
                            <td>{{ $coutAnnexe->fournisseur?->raison_sociale ?? '-' }}</td>
                            <td>{{ $repartitions[$coutAnnexe->repartition] ?? $coutAnnexe->repartition }}</td>
                            <td class="right">
                                {{ $nombre($coutAnnexe->montant) }} {{ $coutAnnexe->devise ?: $devise }}
                                @if($coutAnnexe->devise)<div class="detail">= {{ $nombre($coutAnnexe->montantReference()) }} {{ $devise }}</div>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div class="totals-section">
            <table class="totals-table">
                @foreach($totaux as $code => $total)
                    <tr class="total-row">
                        <td><strong>VALEUR REÇUE HT</strong></td>
                        <td style="text-align: right;"><strong>{{ $nombre($total) }} {{ $code }}</strong></td>
                    </tr>
                @endforeach
                @if($reception->frais->isNotEmpty())
                    <tr>
                        <td>Coûts annexes</td>
                        <td style="text-align: right;">{{ $nombre($fraisTotal) }} {{ $devise }}</td>
                    </tr>
                    <tr class="total-row">
                        <td><strong>COÛT DE REVIENT</strong></td>
                        <td style="text-align: right;"><strong>{{ $nombre($valeurReference + $fraisTotal) }} {{ $devise }}</strong></td>
                    </tr>
                @endif
            </table>
        </div>

        @if($reception->notes)
            <div class="notes-section">
                <div class="notes-title">Notes</div>
                <div class="notes-content">{{ $reception->notes }}</div>
            </div>
        @endif

        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line">Reçu par<br>{{ $reception->auteur->nom ?? '' }}</div>
            </div>
            <div class="signature-spacer"></div>
            <div class="signature-box">
                <div class="signature-line">Livré par (transporteur)</div>
            </div>
        </div>
    </div>

    <div class="footer">
        {{ $entreprise['nom'] }} — document généré le {{ now()->format('d/m/Y à H:i') }}
    </div>
</body>
</html>
