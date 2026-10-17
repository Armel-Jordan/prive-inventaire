@php
    $anglais = $langue === 'en';
    $libelles = $anglais ? [
        'numero' => 'No.', 'date' => 'Date', 'payez' => 'Pay the sum of', 'ordre' => 'To the order of', 'tire' => 'Drawn on',
        'talons' => ['Supplier stub', 'Company stub'], 'facture' => 'Invoice', 'escompte' => 'Discount', 'net' => 'Net',
        'total_escompte' => 'Total discounts', 'total_net' => 'Total net', 'montant' => 'Cheque amount', 'cheque' => 'Cheque no.',
        'autres' => '+ :n more invoice(s), included in the totals', 'debourse' => 'Disbursement',
    ] : [
        'numero' => 'N°', 'date' => 'Date', 'payez' => 'Payez contre ce chèque la somme de', 'ordre' => "À l'ordre de", 'tire' => 'Tiré sur',
        'talons' => ['Talon du fournisseur', "Talon de l'entreprise"], 'facture' => 'Facture', 'escompte' => 'Escompte', 'net' => 'Net',
        'total_escompte' => 'Total des escomptes', 'total_net' => 'Total net', 'montant' => 'Montant du chèque', 'cheque' => 'Chèque n°',
        'autres' => '+ :n autre(s) facture(s), comptée(s) dans les totaux', 'debourse' => 'Déboursé',
    ];
    $date = fn ($valeur) => $valeur ? \Illuminate\Support\Carbon::parse($valeur)->format($anglais ? 'Y-m-d' : 'd/m/Y') : '';
    $nombre = fn ($valeur, $decimales) => $anglais ? number_format((float) $valeur, $decimales, '.', ',') : number_format((float) $valeur, $decimales, ',', ' ');
@endphp
<!DOCTYPE html>
<html lang="{{ $langue }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $anglais ? 'Cheques' : 'Chèques' }}</title>
    <style>
        @page { margin: 8mm 10mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 9px; line-height: 1.35; color: #222; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .zone { height: 90mm; padding: 4mm 2mm; border-bottom: 1px dashed #9ca3af; overflow: hidden; }
        .zone:last-child { border-bottom: none; }
        .ligne { display: table; width: 100%; }
        .gauche, .droite { display: table-cell; vertical-align: top; }
        .droite { text-align: right; }
        .tireur { font-size: 12px; font-weight: bold; }
        .discret { color: #6b7280; font-size: 8px; }
        .numero { font-size: 12px; font-weight: bold; }
        .payez { margin-top: 8mm; font-size: 8px; color: #6b7280; }
        .lettres { margin-top: 2mm; font-size: 10px; font-weight: bold; letter-spacing: 0.3px; border-bottom: 1px solid #9ca3af; padding-bottom: 1mm; }
        .chiffres { display: inline-block; margin-top: 4mm; border: 1.5px solid #222; padding: 2mm 4mm; font-size: 13px; font-weight: bold; }
        .ordre { margin-top: 6mm; }
        .beneficiaire { font-size: 11px; font-weight: bold; }
        .titre-talon { font-size: 8px; text-transform: uppercase; color: #0d9488; font-weight: bold; margin-bottom: 2mm; }
        table.factures { width: 100%; border-collapse: collapse; margin-top: 2mm; }
        table.factures th { text-align: left; font-size: 7.5px; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 1px 3px; }
        table.factures td { padding: 1px 3px; border-bottom: 1px solid #f3f4f6; }
        table.factures .r { text-align: right; }
        .colonne { width: 49%; display: inline-block; vertical-align: top; }
        .totaux { margin-top: 3mm; text-align: right; }
        .totaux span { display: inline-block; margin-left: 8mm; }
    </style>
</head>
<body>
@foreach($cheques as $cheque)
    <div class="page">
        {{-- Le chèque. --}}
        <div class="zone">
            <div class="ligne">
                <div class="gauche">
                    <div class="tireur">{{ $entreprise['nom'] }}</div>
                    @if($cheque['compte'])
                        <div class="discret">{{ $libelles['tire'] }} : {{ $cheque['compte']->banque }} — {{ $cheque['compte']->nom }}@if($cheque['compte']->numero_compte) — {{ $cheque['compte']->numero_compte }}@endif</div>
                    @endif
                </div>
                <div class="droite">
                    <div class="numero">{{ $libelles['numero'] }} {{ $cheque['numero'] }}</div>
                    <div>{{ $libelles['date'] }} : {{ $date($cheque['date']) }}</div>
                </div>
            </div>
            <div class="payez">{{ $libelles['payez'] }}</div>
            <div class="lettres">****{{ $cheque['lettres'] }}****</div>
            <div class="droite" style="display: block;"><span class="chiffres">**{{ $nombre($cheque['montant'], $cheque['decimales']) }} {{ $cheque['symbole'] }}**</span></div>
            <div class="ordre">
                <div class="discret">{{ $libelles['ordre'] }}</div>
                <div class="beneficiaire">{{ $cheque['beneficiaire'] }}</div>
                @foreach($cheque['adresse'] as $ligne)
                    <div>{{ $ligne }}</div>
                @endforeach
            </div>
        </div>

        {{-- Les deux talons : celui du fournisseur, celui de l'entreprise. --}}
        @foreach($libelles['talons'] as $titre)
            <div class="zone">
                <div class="titre-talon">{{ $titre }}</div>
                <div class="ligne">
                    <div class="gauche"><strong>{{ $cheque['code'] }} — {{ $cheque['beneficiaire'] }}</strong></div>
                    <div class="droite">{{ $libelles['cheque'] }} <strong>{{ $cheque['numero'] }}</strong> — {{ $date($cheque['date']) }} — {{ $libelles['debourse'] }} {{ $cheque['debourse']->numero }}</div>
                </div>
                @foreach(array_chunk($cheque['factures'], 10) as $colonne)
                    <div class="colonne">
                        <table class="factures">
                            <thead><tr><th>{{ $libelles['date'] }}</th><th>{{ $libelles['facture'] }}</th><th class="r">{{ $libelles['escompte'] }}</th><th class="r">{{ $libelles['net'] }}</th></tr></thead>
                            <tbody>
                                @foreach($colonne as $facture)
                                    <tr>
                                        <td>{{ $date($facture['date']) }}</td>
                                        <td>{{ $facture['numero'] }}</td>
                                        <td class="r">{{ $facture['escompte'] ? $nombre($facture['escompte'], $cheque['decimales']) : '' }}</td>
                                        <td class="r">{{ $nombre($facture['net'], $cheque['decimales']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
                @if($cheque['autres'])
                    <div class="discret">{{ str_replace(':n', (string) $cheque['autres'], $libelles['autres']) }}</div>
                @endif
                <div class="totaux">
                    <span>{{ $libelles['total_escompte'] }} : {{ $nombre($cheque['total_escompte'], $cheque['decimales']) }}</span>
                    <span>{{ $libelles['total_net'] }} : {{ $nombre($cheque['total_net'], $cheque['decimales']) }}</span>
                    <span><strong>{{ $libelles['montant'] }} : {{ $nombre($cheque['montant'], $cheque['decimales']) }} {{ $cheque['symbole'] }}</strong></span>
                </div>
            </div>
        @endforeach
    </div>
@endforeach
</body>
</html>
