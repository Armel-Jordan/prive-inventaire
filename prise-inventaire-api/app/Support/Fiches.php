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
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;

/**
 * Types de fiches et de documents qui reçoivent des fonctions transverses (balises…) : modèle,
 * module de l'entreprise dont chacun dépend (null : toujours disponible) et libellé lisible.
 */
final class Fiches
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

    /** Le module de cette fiche est-il actif pour l'entreprise de la requête ? */
    public static function accessible(Request $request, string $entite): bool
    {
        $module = self::module($entite);
        $tenant = $request->attributes->get('tenant');

        return ! $module || ! $tenant || $tenant->hasModule($module);
    }

    public static function verifierModule(Request $request, string $entite): void
    {
        $module = self::module($entite);
        abort_unless(self::accessible($request, $entite), 403, "Le module « {$module} » n'est pas activé pour cette entreprise.");
    }

    /** Fiches de l'entreprise courante, supprimées comprises, indexées par identifiant. */
    public static function charger(string $entite, array $ids): \Illuminate\Support\Collection
    {
        $classe = self::modele($entite);

        return $classe::query()
            ->when(in_array(SoftDeletes::class, class_uses_recursive($classe), true), fn ($q) => $q->withTrashed())
            ->whereKey($ids)
            ->get()
            ->keyBy(fn (Model $fiche) => $fiche->getKey());
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
