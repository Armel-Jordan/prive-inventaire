<?php

namespace App\Support;

use App\Models\BonLivraison;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\ComFourEntete;
use App\Models\Devis;
use App\Models\EmployeTenant;
use App\Models\Facture;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Fiches et documents qui acceptent des champs personnalisés, avec le module de l'entreprise dont
 * chacun dépend (null : toujours disponible), et types de champ proposés.
 */
final class ChampsPersonnalises
{
    public const ENTITES = [
        'client' => [Client::class, 'ventes'],
        'fournisseur' => [Fournisseur::class, 'achats'],
        'produit' => [ProduitTenant::class, null],
        'employe' => [EmployeTenant::class, null],
        'devis' => [Devis::class, 'ventes'],
        'commande_client' => [ComClientEntete::class, 'ventes'],
        'facture' => [Facture::class, 'ventes'],
        'bon_livraison' => [BonLivraison::class, 'ventes'],
        'commande_fournisseur' => [ComFourEntete::class, 'achats'],
    ];

    public const TYPES = ['texte', 'texte_long', 'nombre', 'date', 'case', 'liste', 'liste_multiple', 'courriel', 'url', 'telephone'];

    /** Types dont la valeur se choisit dans une liste d'options. */
    public const TYPES_A_CHOIX = ['liste', 'liste_multiple'];

    /** Types qui peuvent interdire les doublons (une même valeur sur deux fiches). */
    public const TYPES_SANS_DOUBLON = ['texte', 'nombre', 'date', 'courriel', 'url', 'telephone'];

    /** Affichage d'un nombre. */
    public const FORMATS_NOMBRE = ['nombre', 'montant', 'pourcentage'];

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
