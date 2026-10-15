<?php

namespace App\Support;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Configuration;
use App\Models\EmployeTenant;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\Secteur;
use App\Models\TenantParametres;
use App\Models\TenantTaxe;
use Closure;

/**
 * Parcours de mise en route : chaque réglage est vérifié dans les données de l'entreprise courante,
 * jamais coché à la main. Les compteurs de qualité signalent les fiches incomplètes. Ajouter une
 * vérification se fait ici (et son libellé dans les traductions du front).
 */
final class ConfigurationACompleter
{
    /**
     * Étapes dans l'ordre conseillé.
     *
     * @return array<string, array<string, array{obligatoire: bool, module: ?string, lien: string, faite: Closure}>>
     */
    public static function etapes(int $tenantId): array
    {
        $parametres = TenantParametres::first();
        $tache = fn (bool $obligatoire, ?string $module, string $lien, Closure $faite) => compact('obligatoire', 'module', 'lien', 'faite');

        return [
            'entreprise' => [
                'entreprise_nom' => $tache(true, null, '/configuration', fn () => filled($parametres?->nom_entreprise)),
                // Les paramètres naissent avec des valeurs par défaut (EUR, TVA 20 %) : seul un enregistrement les confirme.
                'devise' => $tache(true, null, '/configuration', fn () => $parametres?->created_at && $parametres->updated_at?->gt($parametres->created_at)),
                'entreprise_coordonnees' => $tache(false, null, '/configuration', fn () => filled($parametres?->adresse) && filled($parametres?->telephone) && filled($parametres?->email)),
                'entreprise_numeros' => $tache(false, null, '/configuration', fn () => filled($parametres?->siret) || filled($parametres?->tva_numero)),
                'numerotation' => $tache(false, null, '/configuration', fn () => Configuration::query()->exists()),
            ],
            'inventaire' => [
                'secteurs' => $tache(true, null, '/secteurs', fn () => Secteur::query()->exists()),
                'produits' => $tache(true, null, '/produits', fn () => ProduitTenant::query()->exists()),
                'seuils_alerte' => $tache(false, null, '/alertes', fn () => ProduitTenant::where('seuil_alerte', '>', 0)->exists()),
                'employes' => $tache(false, null, '/employes', fn () => EmployeTenant::query()->exists()),
            ],
            'tiers' => [
                'clients' => $tache(true, 'ventes', '/clients', fn () => Client::query()->exists()),
                'taxes' => $tache(false, 'ventes', '/configuration', fn () => TenantTaxe::query()->exists()),
                'fournisseurs' => $tache(true, 'achats', '/fournisseurs', fn () => Fournisseur::query()->exists()),
            ],
            'utilisateurs' => [
                // AdminUser n'est pas cloisonné par le scope : filtre explicite sur l'entreprise.
                'utilisateurs' => $tache(false, null, '/employes', fn () => AdminUser::where('tenant_id', $tenantId)->where('role', '!=', 'admin')->exists()),
            ],
        ];
    }

    /**
     * Compteurs de fiches incomplètes (un compteur à zéro n'est pas affiché).
     *
     * @return array<string, array{module: ?string, lien: string, nombre: Closure}>
     */
    public static function qualite(): array
    {
        $vide = fn (string $colonne) => fn ($q) => $q->whereNull($colonne)->orWhere($colonne, '');
        $compteur = fn (?string $module, string $lien, Closure $nombre) => compact('module', 'lien', 'nombre');

        return [
            'produits_sans_prix' => $compteur(null, '/produits', fn () => ProduitTenant::where('actif', true)->where(fn ($q) => $q->whereNull('prix_unitaire')->orWhere('prix_unitaire', '<=', 0))->count()),
            'produits_sans_secteur' => $compteur(null, '/produits', fn () => ProduitTenant::where('actif', true)->whereNull('secteur_id')->count()),
            'clients_sans_courriel' => $compteur('ventes', '/clients', fn () => Client::where('actif', true)->where($vide('email'))->count()),
            'clients_sans_telephone' => $compteur('ventes', '/clients', fn () => Client::where('actif', true)->where($vide('telephone'))->count()),
            'fournisseurs_sans_courriel' => $compteur('achats', '/fournisseurs', fn () => Fournisseur::where('actif', true)->where($vide('email'))->count()),
            'employes_sans_courriel' => $compteur(null, '/employes', fn () => EmployeTenant::where('actif', true)->where($vide('email'))->count()),
        ];
    }
}
