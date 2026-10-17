<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Configuration;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Support\TauxTaxe;
use Illuminate\Support\Facades\DB;

/**
 * Création d'une facture brouillon et de ses lignes (taxes par groupe ou taux unique, échéance de la condition de
 * paiement, devise du document) : la saisie d'une facture et la facturation des reliquats clients passent par ici.
 */
class FacturesVente
{
    public function __construct(
        private readonly TaxesDocument $taxes,
        private readonly ConditionsPaiement $conditions,
        private readonly DevisesVente $devises,
    ) {}

    /**
     * Facture brouillon numérotée. La numérotation automatique des factures doit être active (contrôlée par
     * l'appelant).
     *
     * @param  array{client_id: int, date_facture: string, date_echeance?: ?string, condition_paiement_id?: ?int, devise?: ?string, taux_change?: mixed, notes?: ?string, lignes: list<array{produit_id: int, quantite: int, prix_unitaire_ht: float|string, taux_tva?: mixed, groupe_taxe_id?: ?int, remise_ligne?: mixed, description?: ?string}>}  $donnees
     */
    public function creer(array $donnees, int $tenantId): Facture
    {
        $client = Client::find($donnees['client_id']);
        $condition = $this->conditions->pourFacture($donnees['condition_paiement_id'] ?? null, $client);
        // Devise de la facture : celle demandée, sinon celle du client ; prix saisis dans cette devise.
        $devise = $this->devises->pourDocument($donnees['devise'] ?? null, $donnees['taux_change'] ?? null, $client);
        // Coordonnées du client de passage (pour lui seulement).
        $passage = app(ClientsDePassage::class)->coordonnees($donnees, $client);

        return DB::transaction(function () use ($donnees, $tenantId, $condition, $devise, $passage) {
            $numero = Configuration::consommerNumero('facture', $tenantId);
            $calcul = $this->calculerLignes($donnees['lignes']);
            $lignesCalculees = $calcul['lignes'];
            ['montant_ht' => $montantHt, 'montant_tva' => $montantTva, 'montant_ttc' => $montantTtc] = $calcul;

            $facture = Facture::create($devise + $passage + [
                'tenant_id' => $tenantId,
                'numero' => $numero,
                'client_id' => $donnees['client_id'],
                'date_facture' => $donnees['date_facture'],
                // Sans échéance saisie, celle de la condition de paiement.
                'date_echeance' => $donnees['date_echeance'] ?? ($condition ? $this->conditions->echeance($condition, $donnees['date_facture']) : null),
                'condition_paiement_id' => $condition?->id,
                'statut' => 'brouillon',
                'montant_ht' => $montantHt,
                'montant_tva' => $montantTva,
                'montant_ttc' => $montantTtc,
                'reste_a_payer' => $montantTtc,
                'notes' => $donnees['notes'] ?? null,
            ]);

            foreach ($lignesCalculees as $l) {
                $ligne = FactureLigne::create([
                    'facture_id' => $facture->id,
                    'produit_id' => $l['produit_id'],
                    'quantite' => $l['quantite'],
                    'prix_unitaire_ht' => $l['prix_unitaire_ht'],
                    'taux_tva' => $l['tva'],
                    'groupe_taxe_id' => $l['groupeId'],
                    'remise_ligne' => $l['remise'],
                    'montant_ht' => $l['ht'],
                    'montant_tva' => $l['tvaM'],
                    'montant_ttc' => $l['ttc'],
                    // Texte sous la désignation du produit (la période d'un abonnement, par exemple).
                    'description' => $l['description'] ?? null,
                ]);
                $this->taxes->enregistrer('facture', $ligne->id, $l['detail']);
            }

            return $facture;
        });
    }

    /**
     * Montants de chaque ligne (remise, taxes par groupe ou taux unique) et totaux, sans rien enregistrer : la saisie
     * d'une facture et l'aperçu d'une vente au comptoir calculent de la même façon.
     *
     * @param  list<array{produit_id: int, quantite: int, prix_unitaire_ht: float|string, taux_tva?: mixed, groupe_taxe_id?: ?int, remise_ligne?: mixed}>  $lignes
     * @return array{lignes: list<array<string, mixed>>, montant_ht: float, montant_tva: float, montant_ttc: float}
     */
    public function calculerLignes(array $lignes): array
    {
        $montantHt = 0;
        $montantTva = 0;
        $montantTtc = 0;

        // Taux par défaut de l'entreprise (paramètres) pour les lignes sans taux ni groupe de taxes.
        $tauxDefaut = TauxTaxe::parDefaut();
        $lignesCalculees = array_map(function ($l) use (&$montantHt, &$montantTva, &$montantTtc, $tauxDefaut) {
            $remise = $l['remise_ligne'] ?? 0;
            $ht = $l['quantite'] * $l['prix_unitaire_ht'] * (1 - $remise / 100);
            // Groupe de taxes si la ligne en a un (ou groupe par défaut sans taux saisi), sinon taux unique.
            $groupe = $this->taxes->groupePour($l);
            $detail = $groupe ? $this->taxes->calculer($groupe, $ht) : null;
            $tva = $detail ? $detail['taux_effectif'] : ($l['taux_tva'] ?? $tauxDefaut);
            $tvaM = $detail ? $detail['total_taxes'] : $ht * ($tva / 100);
            $ttc = $ht + $tvaM;
            $montantHt += $ht;
            $montantTva += $tvaM;
            $montantTtc += $ttc;
            $groupeId = $groupe?->id;

            return compact('ht', 'tvaM', 'ttc', 'tva', 'remise', 'detail', 'groupeId') + $l;
        }, $lignes);

        return ['lignes' => $lignesCalculees, 'montant_ht' => $montantHt, 'montant_tva' => $montantTva, 'montant_ttc' => $montantTtc];
    }

    /** Émet une facture brouillon : statut émise, encours du client, acomptes réservés à sa commande appliqués. */
    public function emettre(Facture $facture, ?int $auteur): void
    {
        $facture->statut = 'emise';
        $facture->save();
        $facture->client->updateEncours();
        // Acomptes réservés à la commande de la facture : appliqués d'eux-mêmes à son paiement.
        app(Acomptes::class)->appliquerReserves($facture, $auteur);
    }
}
