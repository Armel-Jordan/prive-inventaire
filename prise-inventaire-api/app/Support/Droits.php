<?php

namespace App\Support;

use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Droits d'un compte par module (voir, créer, modifier, supprimer), d'après la matrice de son rôle : son rôle
 * personnalisé s'il en a un dans son entreprise, sinon le rôle système de même nom (admin, manager, user,
 * readonly). Un administrateur a tous les droits.
 *
 * Sert au middleware « droits », qui contrôle les écritures de l'API : la matrice n'était appliquée qu'au menu,
 * et un compte « user » pouvait modifier les paramètres, les taxes ou les produits par l'API.
 */
class Droits
{
    /** Action => colonne de role_permissions. */
    public const COLONNES = [
        'voir' => 'can_view', 'creer' => 'can_create', 'modifier' => 'can_edit', 'supprimer' => 'can_delete',
    ];

    /**
     * Premier segment de l'adresse (après « api/ ») => module de la matrice. Les préfixes absents ne sont pas
     * contrôlés par module : lectures, données personnelles (profil, notifications), routes déjà réservées par
     * rôle (utilisateurs, rôles, tâches planifiées), pièces jointes et envois rattachés à une fiche.
     */
    private const MODULES = [
        'produits' => 'produits',
        'secteurs' => 'secteurs',
        'scan' => 'inventaires',
        'prises-inventaire' => 'inventaires',
        'mouvements' => 'relocalisation',
        'relocalisation' => 'relocalisation',
        'transferts-planifies' => 'planification',
        'alertes' => 'alertes',
        'approbations' => 'approbations',
        'employes' => 'employes',
        'fournisseurs' => 'fournisseurs',
        'commandes-fournisseur' => 'commandes_fournisseur',
        'receptions' => 'receptions',
        'devis' => 'devis',
        'clients' => 'clients',
        'categories-clients' => 'clients',
        'commandes-client' => 'commandes_client',
        'factures' => 'factures',
        'bons-livraison' => 'bons_livraison',
        'camions' => 'camions',
        'tournees' => 'tournees',
        'zones-preparation' => 'zones_preparation',
        'listes-prix' => 'gestion_prix',
        'grille-prix' => 'gestion_prix',
        'regles-prix' => 'gestion_prix',
        'parametres' => 'configuration',
        'taxes' => 'configuration',
        'groupes-taxes' => 'configuration',
        'configurations' => 'configuration',
        'champs-personnalises' => 'configuration',
        'balises' => 'configuration',
        'courriels' => 'configuration',
        'referentiels' => 'referentiels',
    ];

    /**
     * Écritures qui ne modifient pas les données du module (calculs, aperçus, envois, favoris, valeurs d'une
     * fiche), ou ouvertes à tous par choix : classer un client, écrire une remarque sur une fiche client ou
     * fournisseur (chacun modifie ses propres remarques, voir les contrôleurs des remarques).
     */
    private const LIBRES = [
        'PUT api/clients/{client}/categorie',
        'POST api/clients/{client}/remarques',
        'PUT api/clients/{client}/remarques/{id}',
        'DELETE api/clients/{client}/remarques/{id}',
        'POST api/fournisseurs/{fournisseur}/remarques',
        'PUT api/fournisseurs/{fournisseur}/remarques/{id}',
        'DELETE api/fournisseurs/{fournisseur}/remarques/{id}',
        'POST api/prix/calculer',
        'POST api/groupes-taxes/{id}/calculer',
        'POST api/secteurs/validate-qr',
        'POST api/configurations/{entite}/consommer',
        'POST api/courriels/modeles/apercu',
        'POST api/courriels/envois',
        'POST api/courriels/envois/{id}/reessayer',
        'PUT api/champs-personnalises/valeurs',
        'PUT api/balises/affectations',
        'POST api/clients/{client}/favori',
        'DELETE api/clients/{client}/favori',
    ];

    /** Écritures rattachées à un autre module que leur préfixe. */
    private const PARTICULIERES = [
        'PUT api/approbations/settings' => ['configuration', 'modifier'],
        'PUT api/clients/{client}/liste-prix' => ['gestion_prix', 'modifier'],
        'POST api/factures/commande/{commandeId}' => ['factures', 'creer'],
    ];

    /** Libellés des modules dans les messages de refus. */
    private const LIBELLES = [
        'inventaires' => 'Inventaires', 'relocalisation' => 'Relocalisation', 'planification' => 'Planification',
        'alertes' => 'Alertes de stock', 'approbations' => 'Approbations', 'produits' => 'Produits',
        'secteurs' => 'Secteurs', 'employes' => 'Employés', 'fournisseurs' => 'Fournisseurs',
        'commandes_fournisseur' => 'Commandes fournisseur', 'receptions' => 'Réceptions', 'clients' => 'Clients',
        'commandes_client' => 'Commandes client', 'factures' => 'Factures', 'bons_livraison' => 'Bons de livraison',
        'camions' => 'Camions', 'tournees' => 'Tournées', 'zones_preparation' => 'Zones de préparation',
        'devis' => 'Devis', 'gestion_prix' => 'Gestion des prix', 'configuration' => 'Configuration',
        'referentiels' => 'Référentiels',
    ];

    private const VERBES = ['voir' => 'consulter', 'creer' => 'créer', 'modifier' => 'modifier', 'supprimer' => 'supprimer'];

    /**
     * Rôle effectif d'un compte : rôle personnalisé de son entreprise, sinon rôle système de même nom. Un
     * administrateur garde le rôle système « admin » (tous les droits), même avec un rôle personnalisé.
     */
    public function role(AdminUser $compte): ?object
    {
        if ($compte->role !== 'admin' && ! empty($compte->role_custom_id)) {
            $personnalise = DB::table('roles_custom')->where('id', $compte->role_custom_id)
                ->where(fn ($q) => $q->where('tenant_id', $compte->tenant_id)
                    ->orWhere(fn ($systeme) => $systeme->whereNull('tenant_id')->where('is_system', true)))
                ->first();
            if ($personnalise) {
                return $personnalise;
            }
        }

        return $this->roleSysteme($compte->role ?: 'user') ?? $this->roleSysteme('user');
    }

    private function roleSysteme(string $nom): ?object
    {
        return DB::table('roles_custom')->whereNull('tenant_id')->where('is_system', true)->where('nom', $nom)->first();
    }

    /** Le compte peut-il faire cette action (voir, creer, modifier, supprimer) dans ce module ? */
    public function permet(AdminUser $compte, string $module, string $action): bool
    {
        // Un administrateur a tous les droits, quelle que soit la matrice.
        if ($compte->role === 'admin') {
            return true;
        }

        $role = $this->role($compte);
        if (! $role) {
            return false;
        }

        return (bool) DB::table('role_permissions')->where('role_id', $role->id)->where('module', $module)
            ->value(self::COLONNES[$action]);
    }

    /**
     * Module et action contrôlés pour une écriture de l'API, ou null si elle n'est pas contrôlée par module.
     *
     * @return array{string, string}|null
     */
    public function regle(Request $request): ?array
    {
        $methode = $request->method();
        if (in_array($methode, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }

        $uri = $request->route()?->uri() ?? $request->path();
        $cle = "{$methode} {$uri}";
        if (in_array($cle, self::LIBRES, true)) {
            return null;
        }
        if (isset(self::PARTICULIERES[$cle])) {
            return self::PARTICULIERES[$cle];
        }

        $segments = explode('/', $uri);
        $module = self::MODULES[$segments[1] ?? ''] ?? null;
        if ($module === null) {
            return null;
        }

        // Supprimer, modifier (PUT, ou POST sur un élément existant : valider, annuler…), sinon créer.
        $action = match (true) {
            $methode === 'DELETE' => 'supprimer',
            $methode === 'PUT', $methode === 'PATCH', str_contains($uri, '{') => 'modifier',
            default => 'creer',
        };

        return [$module, $action];
    }

    public function messageRefus(string $module, string $action): string
    {
        $libelle = self::LIBELLES[$module] ?? $module;

        return "Droit insuffisant : votre rôle ne permet pas de {$this->verbe($action)} dans « {$libelle} ».";
    }

    private function verbe(string $action): string
    {
        return self::VERBES[$action] ?? $action;
    }
}
