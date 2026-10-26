<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Configuration;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\TenantParametres;
use App\Support\TauxTaxe;
use Illuminate\Support\Facades\DB;

/**
 * Création et modification d'une facture brouillon et de ses lignes (taxes par groupe ou taux unique, échéance de la
 * condition de paiement, devise du document) : la saisie d'une facture et la facturation des reliquats clients passent
 * par ici.
 */
class FacturesVente
{
    /** Début du numéro provisoire d'un brouillon quand l'entreprise numérote ses factures à l'émission. */
    public const PREFIXE_BROUILLON = 'BR-';

    public function __construct(
        private readonly TaxesDocument $taxes,
        private readonly ConditionsPaiement $conditions,
        private readonly DevisesVente $devises,
    ) {}

    /**
     * Facture brouillon numérotée. La numérotation automatique des factures doit être active (contrôlée par
     * l'appelant).
     *
     * @param  array{client_id: int, date_facture: string, date_echeance?: ?string, condition_paiement_id?: ?int, devise?: ?string, taux_change?: mixed, notes?: ?string, lignes: list<array{produit_id: int, quantite: int, prix_unitaire_ht: float|string, taux_tva?: mixed, groupe_taxe_id?: ?int, remise_ligne?: mixed, description?: ?string, unite?: ?string, facteur?: ?float}>}  $donnees
     */
    public function creer(array $donnees, int $tenantId): Facture
    {
        $client = Client::find($donnees['client_id']);
        $condition = $this->conditions->pourFacture($donnees['condition_paiement_id'] ?? null, $client);
        // Devise de la facture : celle demandée, sinon celle du client ; prix saisis dans cette devise.
        $devise = $this->devises->pourDocument($donnees['devise'] ?? null, $donnees['taux_change'] ?? null, $client);
        // Coordonnées du client de passage (pour lui seulement).
        $passage = app(ClientsDePassage::class)->coordonnees($donnees, $client);
        // Représentant : celui demandé (ou du document d'origine), sinon celui du client ; son taux de commission est figé.
        $entete = app(EntetesVente::class)->pourCreation($donnees, $client);
        $passage += $entete + ['taux_commission' => app(EntetesVente::class)->commission($entete['representant_id'])];

        return DB::transaction(function () use ($donnees, $tenantId, $condition, $devise, $passage) {
            $numero = $this->numeroBrouillon($tenantId);
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
                'numero_suivi' => $donnees['numero_suivi'] ?? null,
            ]);

            $this->creerLignes($facture, $lignesCalculees);
            $this->numeroterBrouillon($facture);

            return $facture;
        });
    }

    /**
     * Modifie une facture brouillon (contrôlé par l'appelant) : en-tête et lignes, remplacées par celles reçues ; taxes,
     * totaux et échéance recalculés comme à la création. Un représentant changé fige son propre taux de commission.
     *
     * @param  array{client_id: int, date_facture: string, date_echeance?: ?string, condition_paiement_id?: ?int, devise?: ?string, taux_change?: mixed, notes?: ?string, lignes: list<array<string, mixed>>}  $donnees
     */
    public function modifier(Facture $facture, array $donnees): Facture
    {
        $client = Client::find($donnees['client_id']);
        $condition = $this->conditions->pourFacture($donnees['condition_paiement_id'] ?? null, $client);
        $devise = $this->devises->pourDocument($donnees['devise'] ?? null, $donnees['taux_change'] ?? null, $client);
        $passage = app(ClientsDePassage::class)->coordonnees($donnees, $client);
        $entete = app(EntetesVente::class)->pourModification($donnees);
        if (array_key_exists('representant_id', $entete) && $entete['representant_id'] !== $facture->representant_id) {
            $entete['taux_commission'] = app(EntetesVente::class)->commission($entete['representant_id']);
        }

        return DB::transaction(function () use ($facture, $donnees, $condition, $devise, $passage, $entete) {
            $calcul = $this->calculerLignes($donnees['lignes']);

            $this->taxes->supprimer('facture', $facture->lignes()->pluck('id'));
            $facture->lignes()->delete();
            $this->creerLignes($facture, $calcul['lignes']);

            $facture->update($devise + $passage + $entete + [
                'client_id' => $donnees['client_id'],
                'date_facture' => $donnees['date_facture'],
                'date_echeance' => $donnees['date_echeance'] ?? ($condition ? $this->conditions->echeance($condition, $donnees['date_facture']) : null),
                'condition_paiement_id' => $condition?->id,
                'montant_ht' => $calcul['montant_ht'],
                'montant_tva' => $calcul['montant_tva'],
                'montant_ttc' => $calcul['montant_ttc'],
                'reste_a_payer' => $calcul['montant_ttc'],
                'notes' => $donnees['notes'] ?? null,
                // Non renvoyé, le numéro de suivi reste.
                'numero_suivi' => array_key_exists('numero_suivi', $donnees) ? $donnees['numero_suivi'] : $facture->numero_suivi,
            ]);

            return $facture->refresh();
        });
    }

    /** Lignes calculées (calculerLignes) enregistrées sur la facture, avec le détail de leurs taxes. */
    private function creerLignes(Facture $facture, array $lignesCalculees): void
    {
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
                // Unité de vente de la ligne et son facteur (vides : l'unité de stock du produit).
                'unite' => $l['unite'] ?? null,
                'facteur' => $l['facteur'] ?? null,
            ]);
            $this->taxes->enregistrer('facture', $ligne->id, $l['detail']);
        }
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

    /**
     * Numérotation à l'émission (réglage de l'entreprise, anomalie A11) : un brouillon reçoit un numéro provisoire
     * « BR-… » et ne consomme la séquence qu'à son émission ; un brouillon supprimé ne laisse pas de trou.
     */
    public static function numeroALEmission(): bool
    {
        return (bool) TenantParametres::query()->value('numero_facture_a_l_emission');
    }

    /** Numéro d'une nouvelle facture brouillon : le suivant de la séquence, ou un provisoire (voir numeroALEmission). */
    public function numeroBrouillon(int $tenantId): string
    {
        return self::numeroALEmission() ? self::PREFIXE_BROUILLON.uniqid() : Configuration::consommerNumero('facture', $tenantId);
    }

    /** Numéro provisoire définitif du brouillon : « BR- » suivi de son identifiant. */
    public function numeroterBrouillon(Facture $facture): void
    {
        if (str_starts_with((string) $facture->numero, self::PREFIXE_BROUILLON)) {
            $facture->update(['numero' => self::PREFIXE_BROUILLON.$facture->id]);
        }
    }

    /**
     * Émet une facture brouillon : vrai numéro s'il était provisoire, statut émise, encours du client, acomptes
     * réservés à sa commande appliqués.
     */
    public function emettre(Facture $facture, ?int $auteur): void
    {
        if (str_starts_with((string) $facture->numero, self::PREFIXE_BROUILLON)) {
            $facture->numero = Configuration::consommerNumero('facture', $facture->tenant_id);
        }
        $facture->statut = 'emise';
        $facture->save();
        $facture->client->updateEncours();
        // Acomptes réservés à la commande de la facture : appliqués d'eux-mêmes à son paiement.
        app(Acomptes::class)->appliquerReserves($facture, $auteur);
    }
}
