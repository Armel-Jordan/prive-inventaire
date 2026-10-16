<?php

namespace App\Services;

use App\Models\BonLivraison;
use App\Models\ComClientEntete;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentPdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Prépare les documents de vente (devis, commande, facture, bon de livraison) dans un format
 * commun, rendu par la vue pdf.document-vente : en-tête de l'entreprise (ses paramètres),
 * destinataire, lignes, totaux avec le détail des taxes quand il existe.
 */
class DocumentsVentePdf
{
    private const STATUTS = [
        'brouillon' => 'Brouillon', 'envoye' => 'Envoyé', 'accepte' => 'Accepté', 'refuse' => 'Refusé', 'expire' => 'Expiré',
        'soumise' => 'Soumise', 'acceptee' => 'Acceptée', 'refusee' => 'Refusée', 'facturee' => 'Facturée',
        'emise' => 'Émise', 'partiellement_payee' => 'Partiellement payée', 'payee' => 'Payée', 'annulee' => 'Annulée',
        'cree' => 'Créé', 'en_preparation' => 'En préparation', 'pret' => 'Prêt', 'livre' => 'Livré', 'annule' => 'Annulé',
    ];

    private const MODES_LIVRAISON = [
        'entreprise' => 'Livraison par l\'entreprise',
        'retrait_client' => 'Retrait par le client',
    ];

    public function __construct(private readonly TaxesDocument $taxes) {}

    public function pdf(array $donnees): DocumentPdf
    {
        // Sous-ensemble de police : seuls les caractères utilisés sont embarqués (PDF bien plus léger).
        return Pdf::loadView('pdf.document-vente', $donnees)
            ->setPaper('A4', 'portrait')
            ->setOption('isFontSubsettingEnabled', true);
    }

    public function devis(Devis $devis): array
    {
        $devis->loadMissing(['client', 'lignes.produit']);

        return $this->document('DEVIS', $devis, [
            'Date du devis' => $this->date($devis->date_devis),
            'Valable jusqu\'au' => $this->date($devis->date_validite),
        ], [
            'destinataire' => $this->client($devis->client),
            'lignes' => $devis->lignes->map(fn ($l) => $this->lignePrix($l, $l->prix_unitaire, 0, $l->montant_ligne))->all(),
            // Les devis sont établis hors taxes.
            'totaux' => [['Total HT', (float) $devis->montant_total, true]],
            'mention' => 'Montants hors taxes.',
            'notes' => $devis->notes,
            'signatures' => ['Bon pour accord, date et signature du client'],
        ]);
    }

    public function commande(ComClientEntete $commande): array
    {
        $commande->loadMissing(['client', 'lignes.produit']);

        return $this->document('COMMANDE', $commande, [
            'Date de commande' => $this->date($commande->date_commande),
            'Livraison souhaitée' => $this->date($commande->date_livraison_souhaitee),
        ], [
            'destinataire' => $this->client($commande->client),
            'lignes' => $commande->lignes->map(fn ($l) => $this->lignePrix($l, $l->prix_unitaire_ht, $l->remise_ligne, $l->montant_ht))->all(),
            'totaux' => $this->totaux('commande_client', $commande, $commande->lignes),
            'notes' => $commande->notes,
        ]);
    }

    public function facture(Facture $facture): array
    {
        $facture->loadMissing(['client', 'lignes.produit']);

        return $this->document('FACTURE', $facture, [
            'Date de facture' => $this->date($facture->date_facture),
            'Échéance' => $this->date($facture->date_echeance),
        ], [
            'destinataire' => $this->client($facture->client),
            'lignes' => $facture->lignes->map(fn ($l) => $this->lignePrix($l, $l->prix_unitaire_ht, $l->remise_ligne, $l->montant_ht))->all(),
            'totaux' => $this->totaux('facture', $facture, $facture->lignes),
            'reglement' => [
                ['Payé', (float) $facture->montant_paye],
                ['Reste à payer', (float) $facture->reste_a_payer],
            ],
            'notes' => $facture->notes,
        ]);
    }

    public function bonLivraison(BonLivraison $bon): array
    {
        $bon->loadMissing(['facture.client', 'lignes.produit']);

        return $this->document('BON DE LIVRAISON', $bon, array_filter([
            'Facture' => $bon->facture?->numero,
            'Mode de livraison' => self::MODES_LIVRAISON[$bon->mode_livraison] ?? $bon->mode_livraison,
            'Date de livraison' => $this->date($bon->date_livraison),
        ]), [
            'destinataire' => $this->client($bon->facture?->client, true),
            'colonnes' => 'livraison',
            'lignes' => $bon->lignes->map(fn ($l) => [
                'reference' => $l->produit?->numero ?? '-',
                'designation' => $l->produit?->description ?? 'Produit #'.$l->produit_id,
                'a_livrer' => $l->quantite_a_livrer,
                'preparee' => $l->quantite_preparee,
                'livree' => $l->quantite_livree,
            ])->all(),
            'notes' => $bon->notes_livraison,
            'signatures' => ['Livré par', 'Reçu par le client (date et signature)'],
        ]);
    }

    /** Champs communs à tous les documents. */
    private function document(string $titre, Model $document, array $infos, array $contenu): array
    {
        $parametres = TenantParametres::first();
        $tenant = Tenant::find(app(TenantContext::class)->getTenantId());

        return $contenu + [
            'titre' => $titre,
            'numero' => $document->numero,
            'statut' => self::STATUTS[$document->statut] ?? ucfirst(str_replace('_', ' ', (string) $document->statut)),
            'infos' => array_filter($infos),
            'devise' => $parametres?->devise_symbole ?: '€',
            'entreprise' => [
                'nom' => $parametres?->nom_entreprise ?: ($tenant?->nom ?? ''),
                'adresse' => $parametres?->adresse,
                'telephone' => $parametres?->telephone,
                'email' => $parametres?->email,
                'mentions' => implode(' — ', array_filter([
                    $parametres?->siret ? 'N° d\'entreprise : '.$parametres->siret : null,
                    $parametres?->tva_numero ? 'N° de taxe : '.$parametres->tva_numero : null,
                ])),
            ],
            'colonnes' => $contenu['colonnes'] ?? 'prix',
            'totaux' => $contenu['totaux'] ?? [],
            'reglement' => $contenu['reglement'] ?? [],
            'mention' => $contenu['mention'] ?? null,
            'signatures' => $contenu['signatures'] ?? [],
        ];
    }

    private function client(?Model $client, bool $livraison = false): array
    {
        if (! $client) {
            return ['nom' => '-', 'lignes' => []];
        }

        $adresse = $livraison && $client->adresse_livraison ? $client->adresse_livraison : $client->adresse_facturation;

        return [
            'nom' => $client->raison_sociale,
            'lignes' => array_values(array_filter([
                $adresse,
                trim(($client->code_postal ?? '').' '.($client->ville ?? '')),
                $client->telephone ? 'Tél. : '.$client->telephone : null,
                $client->email,
            ])),
        ];
    }

    private function lignePrix(Model $ligne, mixed $prixUnitaire, mixed $remise, mixed $total): array
    {
        return [
            'reference' => $ligne->produit?->numero ?? '-',
            'designation' => $ligne->produit?->description ?? 'Produit #'.$ligne->produit_id,
            'quantite' => $ligne->quantite,
            'prix_unitaire' => (float) $prixUnitaire,
            'remise' => (float) $remise,
            'total' => (float) $total,
        ];
    }

    /** Totaux HT / taxes / TTC : une ligne par taxe si le document a un détail, sinon la TVA globale. */
    private function totaux(string $type, Model $document, iterable $lignes): array
    {
        $recapitulatif = $this->taxes->recapitulatif($type, collect($lignes)->pluck('id'));
        $taxes = $recapitulatif
            ? array_map(fn ($t) => [$t['nom'].' ('.$this->pourcentage($t['taux']).')', $t['montant']], $recapitulatif)
            : [[$this->libelleTvaUnique($lignes), (float) $document->montant_tva]];

        return array_merge(
            [['Sous-total HT', (float) $document->montant_ht]],
            $taxes,
            [['Total TTC', (float) $document->montant_ttc, true]],
        );
    }

    private function libelleTvaUnique(iterable $lignes): string
    {
        $taux = collect($lignes)->pluck('taux_tva')->map(fn ($t) => (float) $t)->unique();

        return $taux->count() === 1 ? 'TVA ('.$this->pourcentage($taux->first()).')' : 'TVA';
    }

    private function pourcentage(float $taux): string
    {
        return rtrim(rtrim(number_format($taux, 4, ',', ' '), '0'), ',').' %';
    }

    private function date(mixed $date): ?string
    {
        return $date ? Carbon::parse($date)->format('d/m/Y') : null;
    }
}
