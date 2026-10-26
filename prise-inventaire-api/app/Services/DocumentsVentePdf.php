<?php

namespace App\Services;

use App\Models\Avoir;
use App\Models\BonLivraison;
use App\Models\ClientContact;
use App\Models\ClientProduit;
use App\Models\ComClientEntete;
use App\Models\DemandeAcompte;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\Representant;
use App\Support\EntreprisePdf;
use App\Support\Expedition;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentPdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Prépare les documents de vente (devis, commande, facture, note de crédit, bon de livraison) dans un format
 * commun, rendu par la vue pdf.document-vente : en-tête de l'entreprise (ses paramètres),
 * destinataire, lignes, totaux avec le détail des taxes quand il existe.
 */
class DocumentsVentePdf
{
    private const STATUTS = [
        'brouillon' => 'Brouillon', 'envoye' => 'Envoyé', 'accepte' => 'Accepté', 'refuse' => 'Refusé', 'expire' => 'Expiré',
        'soumise' => 'Soumise', 'acceptee' => 'Acceptée', 'refusee' => 'Refusée', 'facturee' => 'Facturée',
        'emise' => 'Émise', 'partiellement_payee' => 'Partiellement payée', 'payee' => 'Payée', 'annulee' => 'Annulée',
        'creditee' => 'Créditée', 'emis' => 'Émise', 'solde' => 'Soldée', 'envoyee' => 'Envoyée',
        'cree' => 'Créé', 'en_preparation' => 'En préparation', 'pret' => 'Prêt', 'livre' => 'Livré', 'annule' => 'Annulé',
        'en_livraison' => 'En livraison', 'livre_complet' => 'Livré', 'livre_partiel' => 'Livré en partie',
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
        $devis->loadMissing(['client', 'lignes.produit', 'conditionPaiement']);

        return $this->document('DEVIS', $devis, [
            'Date du devis' => $this->date($devis->date_devis),
            'Valable jusqu\'au' => $this->date($devis->date_validite),
            'Conditions de paiement' => $devis->conditionPaiement?->libelle,
        ], [
            'destinataire' => $this->client($devis->client, false, $devis),
            'lignes' => $this->lignesPrix($devis->client_id, $devis->lignes, fn ($l) => [$l->prix_unitaire, 0, $l->montant_ligne]),
            // Les devis sont établis hors taxes.
            'totaux' => [['Total HT', (float) $devis->montant_total, true]],
            'mention' => 'Montants hors taxes.',
            'notes' => $devis->notes,
            'signatures' => ['Bon pour accord, date et signature du client'],
        ]);
    }

    public function commande(ComClientEntete $commande): array
    {
        $commande->loadMissing(['client', 'lignes.produit', 'conditionPaiement']);

        return $this->document('COMMANDE', $commande, [
            'Date de commande' => $this->date($commande->date_commande),
            'Livraison souhaitée' => $this->date($commande->date_livraison_souhaitee),
            'Conditions de paiement' => $commande->conditionPaiement?->libelle,
        ], [
            'destinataire' => $this->client($commande->client, false, $commande),
            'lignes' => $this->lignesPrix($commande->client_id, $commande->lignes, fn ($l) => [$l->prix_unitaire_ht, $l->remise_ligne, $l->montant_ht]),
            'totaux' => $this->totaux('commande_client', $commande, $commande->lignes),
            'notes' => $commande->notes,
        ]);
    }

    public function facture(Facture $facture): array
    {
        $facture->loadMissing(['client', 'lignes.produit', 'conditionPaiement']);

        return $this->document('FACTURE', $facture, [
            'Date de facture' => $this->date($facture->date_facture),
            'Échéance' => $this->date($facture->date_echeance),
            'Conditions de paiement' => $facture->conditionPaiement?->libelle,
        ], [
            'destinataire' => $this->client($facture->client, false, $facture),
            'lignes' => $this->lignesPrix($facture->client_id, $facture->lignes, fn ($l) => [$l->prix_unitaire_ht, $l->remise_ligne, $l->montant_ht]),
            'totaux' => $this->totaux('facture', $facture, $facture->lignes),
            'reglement' => array_values(array_filter([
                ['Payé', (float) $facture->montant_paye],
                (float) $facture->montant_escompte > 0 ? ['Escompte', (float) $facture->montant_escompte] : null,
                (float) $facture->montant_credite > 0 ? ['Crédité', (float) $facture->montant_credite] : null,
                ['Reste à payer', (float) $facture->reste_a_payer],
            ])),
            'notes' => $facture->notes,
        ]);
    }

    public function avoir(Avoir $avoir): array
    {
        $avoir->loadMissing(['client', 'facture:id,numero,date_facture', 'lignes.produit']);

        return $this->document('NOTE DE CRÉDIT', $avoir, [
            'Date' => $this->date($avoir->date_avoir),
            'Facture créditée' => $avoir->facture ? trim($avoir->facture->numero.' du '.$this->date($avoir->facture->date_facture)) : null,
            'Motif' => $avoir->motif,
        ], [
            'destinataire' => $this->client($avoir->client, false, $avoir->facture),
            'lignes' => $this->lignesPrix($avoir->client_id, $avoir->lignes, fn ($l) => [$l->prix_unitaire_ht, $l->remise_ligne, $l->montant_ht]),
            'totaux' => $this->totaux('avoir', $avoir, $avoir->lignes),
            'reglement' => $avoir->statut === Avoir::BROUILLON ? [] : array_values(array_filter([
                ['Imputé sur des factures', (float) $avoir->montant_impute],
                (float) $avoir->montant_rembourse > 0 ? ['Remboursé', (float) $avoir->montant_rembourse] : null,
                ['Crédit disponible', $avoir->credit_disponible],
            ])),
            'mention' => 'Montants crédités au client.',
            'notes' => $avoir->notes,
        ]);
    }

    /** Demande d'acompte : une ligne (sa description) et le montant demandé, sans taxe. */
    public function demandeAcompte(DemandeAcompte $demande): array
    {
        $demande->loadMissing(['client', 'devis:id,numero', 'commande:id,numero']);

        return $this->document("DEMANDE D'ACOMPTE", $demande, [
            'Date' => $this->date($demande->date_demande),
            'Devis' => $demande->devis?->numero,
            'Commande' => $demande->commande?->numero,
        ], [
            'destinataire' => $this->client($demande->client),
            'lignes' => [[
                'reference' => '-',
                'designation' => $demande->description,
                'quantite' => 1,
                'prix_unitaire' => (float) $demande->montant,
                'remise' => 0.0,
                'total' => (float) $demande->montant,
            ]],
            'totaux' => [['Acompte demandé', (float) $demande->montant, true]],
            'mention' => "Aucune taxe : ce document n'est pas une facture.",
        ]);
    }

    public function bonLivraison(BonLivraison $bon): array
    {
        $bon->loadMissing(['facture.client', 'lignes.produit']);
        $referencesLivraison = $this->referencesClient($bon->facture?->client_id, $bon->lignes);

        return $this->document('BON DE LIVRAISON', $bon, array_filter([
            'Facture' => $bon->facture?->numero,
            'Mode de livraison' => self::MODES_LIVRAISON[$bon->mode_livraison] ?? $bon->mode_livraison,
            'Date de livraison' => $this->date($bon->date_livraison),
        ]), [
            // L'adresse « Livrer à » du bon (copiée de la facture), sinon celle de la fiche du client.
            'destinataire' => filled($bon->livraison_adresse) && ! ($bon->facture?->client?->de_passage)
                ? ['nom' => $bon->facture?->client?->raison_sociale ?? '-', 'lignes' => explode("\n", (string) $bon->livraison_adresse)]
                : $this->client($bon->facture?->client, true, $bon->facture),
            'colonnes' => 'livraison',
            'lignes' => $bon->lignes->map(fn ($l) => [
                'reference' => $l->produit?->numero ?? '-',
                'designation' => $this->designation($l, $referencesLivraison),
                'a_livrer' => $l->quantite_a_livrer,
                'preparee' => $l->quantite_preparee,
                'livree' => $l->quantite_livree,
                'unite' => $l->unite,
                'mesure' => $l->produit?->mesure,
            ])->all(),
            'notes' => $bon->notes_livraison,
            // Signature électronique du client, si elle a été recueillie à la livraison.
            'signatures' => ['Livré par', filled($bon->getAttributes()['signature_client'] ?? null) ? [
                'libelle' => 'Reçu par le client',
                'image' => $bon->getAttributes()['signature_client'],
                'detail' => implode(' — ', array_filter([$bon->signataire_nom, $bon->signe_le?->format('d/m/Y')])),
            ] : 'Reçu par le client (date et signature)'],
        ]);
    }

    /** Champs communs à tous les documents. */
    private function document(string $titre, Model $document, array $infos, array $contenu): array
    {
        $entete = EntreprisePdf::entete();
        // Représentant commercial du devis, de la commande ou de la facture.
        if ($document->getAttribute('representant_id')) {
            $infos['Représentant'] = Representant::withTrashed()->whereKey($document->getAttribute('representant_id'))->value('nom');
        }
        // Numéro de commande du client (socle S4.1).
        if (filled($document->getAttribute('numero_commande_client'))) {
            $infos['N° commande client'] = $document->getAttribute('numero_commande_client');
        }
        // Contact du client et « Livrer à » (socle S4.1) ; le bon de livraison met l'adresse en destinataire.
        if ($document->getAttribute('contact_id') && ($contact = ClientContact::query()->find($document->getAttribute('contact_id')))) {
            $infos['Contact'] = implode(' — ', array_filter([$contact->nom, $contact->telephone]));
        }
        if (filled($document->getAttribute('livraison_adresse')) && ! $document instanceof BonLivraison) {
            $infos['Livrer à'] = str_replace("\n", ', ', (string) $document->getAttribute('livraison_adresse'));
        }
        if (filled($document->getAttribute('instructions_livraison'))) {
            $infos['Instructions de livraison'] = $document->getAttribute('instructions_livraison');
        }
        // Transporteur et numéro de suivi (phase 1.11b) : « Expédié par » sur le bon de livraison.
        if ($expedition = Expedition::de($document)) {
            $infos += array_filter([
                ($document instanceof BonLivraison ? 'Expédié par' : 'Transporteur') => $expedition['transporteur'],
                'N° de suivi' => $expedition['numero_suivi'],
            ]);
        }
        // Document en devise étrangère : ses montants dans sa devise, avec son taux parmi les informations.
        if (! empty($document->devise)) {
            $entete['devise'] = app(DevisesVente::class)->symbole($document->devise);
            $infos['Devise'] = $document->devise.' (1 '.$document->devise.' = '.rtrim(rtrim(number_format((float) $document->taux_change, 6, ',', ' '), '0'), ',').' '.app(DevisesVente::class)->reference().')';
        }

        return $contenu + [
            'titre' => $titre,
            // Une note de crédit brouillon n'a pas encore de numéro.
            'numero' => $document->numero ?? '-',
            'statut' => self::STATUTS[$document->statut] ?? ucfirst(str_replace('_', ' ', (string) $document->statut)),
            'infos' => array_filter($infos),
            'devise' => $entete['devise'],
            'entreprise' => $entete['entreprise'],
            'colonnes' => $contenu['colonnes'] ?? 'prix',
            'totaux' => $contenu['totaux'] ?? [],
            'reglement' => $contenu['reglement'] ?? [],
            'mention' => $contenu['mention'] ?? null,
            'notes' => $contenu['notes'] ?? null,
            'signatures' => $contenu['signatures'] ?? [],
        ];
    }

    /**
     * Destinataire d'un document : la fiche du client, ou, pour le client de passage, les coordonnées notées sur le
     * document (celles de sa facture pour une note de crédit ou un bon de livraison).
     */
    private function client(?Model $client, bool $livraison = false, ?Model $document = null): array
    {
        if (! $client) {
            return ['nom' => '-', 'lignes' => []];
        }
        if ($client->de_passage && filled($document?->getAttribute('passage_nom'))) {
            return [
                'nom' => $document->passage_nom,
                'lignes' => array_values(array_filter([
                    $document->passage_adresse,
                    $document->passage_telephone ? 'Tél. : '.$document->passage_telephone : null,
                    $document->passage_courriel,
                ])),
            ];
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

    /**
     * Lignes à prix d'un document, avec la référence de chaque produit chez le client du document (phase 1.2c).
     *
     * @param  callable(Model): array{0: mixed, 1: mixed, 2: mixed}  $montants  prix unitaire, remise, total d'une ligne
     */
    private function lignesPrix(?int $clientId, iterable $lignes, callable $montants): array
    {
        $references = $this->referencesClient($clientId, $lignes);

        return collect($lignes)->map(fn (Model $ligne) => $this->lignePrix($ligne, ...[...$montants($ligne), $references]))->all();
    }

    /** Références des produits des lignes chez le client : id du produit => référence. */
    private function referencesClient(?int $clientId, iterable $lignes): array
    {
        $produits = collect($lignes)->pluck('produit_id')->filter()->unique()->values()->all();

        return array_map(fn (array $reference) => $reference['reference'], ClientProduit::pourClient($clientId, $produits));
    }

    /** Désignation du produit, le texte propre à la ligne s'il y en a un (période d'un abonnement…), la référence du client. */
    private function designation(Model $ligne, array $references): string
    {
        return implode("\n", array_filter([
            $ligne->produit?->description ?? 'Produit #'.$ligne->produit_id,
            $ligne->description ?? null,
            isset($references[$ligne->produit_id]) ? 'Réf. client : '.$references[$ligne->produit_id] : null,
        ]));
    }

    private function lignePrix(Model $ligne, mixed $prixUnitaire, mixed $remise, mixed $total, array $references = []): array
    {
        return [
            'reference' => $ligne->produit?->numero ?? '-',
            'designation' => $this->designation($ligne, $references),
            'quantite' => $ligne->quantite,
            // Unité de vente de la ligne (la caisse de 12) ; sinon l'unité de stock du produit.
            'unite' => $ligne->unite ?? null,
            'mesure' => $ligne->produit?->mesure,
            'prix_unitaire' => (float) $prixUnitaire,
            'remise' => (float) $remise,
            'total' => (float) $total,
        ];
    }

    /**
     * Totaux HT / taxes / TTC : une ligne par taxe si le document a un détail, sinon la TVA globale (aussi pour le
     * reçu d'une vente au comptoir).
     */
    public function totaux(string $type, Model $document, iterable $lignes): array
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
