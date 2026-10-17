<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Consignation;
use App\Models\ProduitTenant;
use App\Models\TenantParametres;
use App\Support\EntreprisePdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentPdf;

/**
 * Documents de la consignation : le bon de dépôt (sans montant : la marchandise reste à l'entreprise) et le rapport
 * « Consignations chez les clients » (client par client, quantités, coût, valeur, dernier dépôt, sous-totaux).
 */
class ConsignationsPdf
{
    public function __construct(private readonly Consignations $consignations) {}

    public function bonDeDepot(Consignation $consignation): DocumentPdf
    {
        return Pdf::loadView('pdf.consignation-depot', [
            'entreprise' => EntreprisePdf::entete()['entreprise'],
            'consignation' => $consignation->loadMissing(['client', 'lignes.produit']),
        ])->setPaper('A4')->setOption('isFontSubsettingEnabled', true);
    }

    /** Données du rapport : un bloc par client (classés par nom), ses produits (par numéro), sous-totaux et total. */
    public function rapport(?int $clientId, bool $aussiNuls): array
    {
        $soldes = $this->consignations->soldes($clientId, $aussiNuls);
        $clients = Client::withTrashed()->whereKey($soldes->pluck('client_id')->unique())->get(['id', 'code', 'raison_sociale'])->keyBy('id');
        $produits = ProduitTenant::withTrashed()->whereKey($soldes->pluck('produit_id')->unique())->get(['id', 'numero', 'description'])->keyBy('id');
        $blocs = $soldes->groupBy('client_id')->map(fn ($lignes, $id) => [
            'client' => $clients[$id]?->only(['id', 'code', 'raison_sociale']),
            'lignes' => $lignes->map(fn (array $s) => $s + ['produit' => $produits[$s['produit_id']]?->only(['id', 'numero', 'description'])])
                ->sortBy(fn ($s) => $s['produit']['numero'] ?? '')->values()->all(),
            'quantite' => (int) $lignes->sum('quantite'),
            'valeur' => round($lignes->sum(fn ($s) => $s['valeur'] ?? 0), 2),
        ])->sortBy(fn ($b) => $b['client']['raison_sociale'] ?? '')->values();
        $client = $clientId ? $clients[$clientId] ?? Client::withTrashed()->find($clientId) : null;

        return [
            'filtre' => trim(($client ? "Client : {$client->code} - {$client->raison_sociale}" : 'Tous les clients').($aussiNuls ? ', produits à quantité nulle inclus' : '')),
            'devise' => (string) (TenantParametres::query()->value('devise_symbole') ?? ''),
            'clients' => $blocs->all(),
            'quantite' => (int) $blocs->sum('quantite'),
            'valeur' => round($blocs->sum('valeur'), 2),
        ];
    }

    public function rapportPdf(array $rapport): DocumentPdf
    {
        return Pdf::loadView('pdf.consignations-rapport', ['entreprise' => EntreprisePdf::entete()['entreprise'], 'rapport' => $rapport, 'genere_le' => now()])
            ->setPaper('A4', 'landscape')->setOption('isFontSubsettingEnabled', true);
    }
}
