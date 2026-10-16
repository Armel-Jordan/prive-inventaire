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
        'bon_livraison' => [BonLivraison::class, 'ventes'],
        'commande_fournisseur' => [ComFourEntete::class, 'achats'],
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
