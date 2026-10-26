<?php

namespace App\Support;

use App\Models\BonLivraison;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\ComFourEntete;
use App\Models\Debourse;
use App\Models\Devis;
use App\Models\EmployeTenant;
use App\Models\Encaissement;
use App\Models\Facture;
use App\Models\FactureFournisseur;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\TransactionBancaire;
use Illuminate\Database\Eloquent\Model;

/**
 * Fiches et documents auxquels on peut joindre des fichiers, avec le module de l'entreprise dont
 * chacun dépend (null : toujours disponible). Ajouter une entrée suffit à ouvrir les pièces
 * jointes d'un nouvel écran.
 */
final class PiecesJointes
{
    public const ENTITES = [
        'client' => [Client::class, 'ventes'],
        'fournisseur' => [Fournisseur::class, 'achats'],
        'produit' => [ProduitTenant::class, null],
        'employe' => [EmployeTenant::class, null],
        'devis' => [Devis::class, 'ventes'],
        'commande_client' => [ComClientEntete::class, 'ventes'],
        'facture' => [Facture::class, 'ventes'],
        // Encaissement : la numérisation du chèque, l'avis de virement.
        'encaissement' => [Encaissement::class, 'ventes'],
        'bon_livraison' => [BonLivraison::class, 'ventes'],
        'commande_fournisseur' => [ComFourEntete::class, 'achats'],
        // La facture reçue du fournisseur (PDF, photo) se joint à sa saisie.
        'facture_fournisseur' => [FactureFournisseur::class, 'achats'],
        // Déboursé : la copie du chèque, l'avis de virement.
        'debourse' => [Debourse::class, 'achats'],
        // Transaction bancaire : l'avis de la banque, le justificatif des frais.
        'transaction_bancaire' => [TransactionBancaire::class, 'finance'],
    ];

    /**
     * Droits de chaque type de fiche (App\Support\Permissions) : consulter ses pièces demande de voir la fiche ; en
     * ajouter, en modifier ou en retirer demande de pouvoir modifier la fiche (transaction bancaire : la saisir).
     */
    public const DROITS = [
        'client' => ['clients', 'modifier'],
        'fournisseur' => ['fournisseurs', 'modifier'],
        'produit' => ['produits', 'modifier'],
        'employe' => ['employes', 'modifier'],
        'devis' => ['devis', 'modifier'],
        'commande_client' => ['commandes_client', 'modifier'],
        'facture' => ['factures', 'modifier'],
        'encaissement' => ['encaissements', 'modifier'],
        'bon_livraison' => ['bons_livraison', 'modifier'],
        'commande_fournisseur' => ['commandes_fournisseur', 'modifier'],
        'facture_fournisseur' => ['factures_fournisseurs', 'modifier'],
        'debourse' => ['debourses', 'modifier'],
        'transaction_bancaire' => ['banque', 'saisir'],
    ];

    /** Types que le navigateur peut afficher sans risque ; les autres sont toujours téléchargés. */
    public const TYPES_AFFICHABLES = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'text/plain'];

    public static function entites(): array
    {
        return array_keys(self::ENTITES);
    }

    /** @return class-string<Model> */
    public static function modele(string $entite): string
    {
        return self::ENTITES[$entite][0];
    }

    public static function module(string $entite): ?string
    {
        return self::ENTITES[$entite][1] ?? null;
    }

    /** Module des droits de la fiche (Permissions::MODULES). */
    public static function moduleDroits(string $entite): string
    {
        return self::DROITS[$entite][0];
    }

    /** Droit qui permet d'ajouter, de modifier ou de retirer les pièces de ce type de fiche. */
    public static function actionEcriture(string $entite): string
    {
        return self::DROITS[$entite][1];
    }

    /** Libellé lisible : raison sociale (tiers), prénom et nom (employé), sinon numéro et description. */
    public static function libelle(Model $modele): string
    {
        if (filled($modele->getAttribute('raison_sociale'))) {
            return (string) $modele->getAttribute('raison_sociale');
        }
        if (filled($modele->getAttribute('nom'))) {
            return trim($modele->getAttribute('prenom').' '.$modele->getAttribute('nom'));
        }

        $libelle = implode(' — ', array_filter([$modele->getAttribute('numero'), $modele->getAttribute('description')], 'filled'));

        return $libelle !== '' ? $libelle : '#'.$modele->getKey();
    }
}
