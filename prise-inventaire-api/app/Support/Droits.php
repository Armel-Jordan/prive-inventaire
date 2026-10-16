<?php

namespace App\Support;

use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Droits d'un compte, sur le modèle de fripv2 :
 * - ses groupes : son rôle (personnalisé dans son entreprise, sinon le rôle système de même nom : admin,
 *   manager, user, readonly) et ses groupes supplémentaires actifs ; les droits des groupes s'additionnent ;
 * - par groupe, les droits de base de chaque module (voir, créer, modifier, supprimer) et les actions nommées
 *   (voir App\Support\Permissions) ; une action qu'un groupe ne règle pas suit le droit de base indiqué ;
 * - les exceptions du compte : une permission accordée en plus, ou refusée (le refus l'emporte sur les groupes).
 * Un administrateur a tous les droits.
 *
 * Sert au middleware « droits », qui contrôle les écritures de l'API et les lectures des données sensibles
 * (comptabilité, comptes clients, valorisation, espace client), et à /permissions/me pour les écrans.
 */
class Droits
{
    /** Action de base => colonne de role_permissions. */
    public const COLONNES = [
        'voir' => 'can_view', 'creer' => 'can_create', 'modifier' => 'can_edit', 'supprimer' => 'can_delete',
    ];

    /**
     * Premier segment de l'adresse (après « api/ ») => module de la matrice, pour les écritures. Les préfixes
     * absents ne sont pas contrôlés par module : données personnelles (profil, notifications), routes réservées
     * par rôle (utilisateurs, rôles, tâches planifiées), pièces jointes et envois rattachés à une fiche.
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
        'commandes-client' => 'commandes_client',
        'factures' => 'factures',
        'avoirs' => 'avoirs',
        'bons-livraison' => 'bons_livraison',
        'camions' => 'camions',
        'tournees' => 'tournees',
        'zones-preparation' => 'zones_preparation',
        'listes-prix' => 'gestion_prix',
        'grille-prix' => 'gestion_prix',
        'regles-prix' => 'gestion_prix',
        'portail-clients' => 'espace_client',
        'parametres' => 'configuration',
        'taxes' => 'configuration',
        'groupes-taxes' => 'configuration',
        'configurations' => 'configuration',
        'champs-personnalises' => 'configuration',
        'balises' => 'configuration',
        'courriels' => 'configuration',
        'referentiels' => 'referentiels',
    ];

    /** Écritures de tout un préfixe rattachées à une action nommée. */
    private const ECRITURES_ACTION = [
        'categories-clients' => ['clients', 'categories'],
    ];

    /** Lectures contrôlées (données sensibles) : préfixe => module ; il faut le droit de le consulter. */
    private const LECTURES = [
        'comptabilite' => 'comptabilite',
        'comptes-clients' => 'comptes_clients',
        'portail-clients' => 'espace_client',
    ];

    /** États comptables : il faut le droit de voir les états. */
    private const ETATS_COMPTABLES = [
        'api/comptabilite/balance', 'api/comptabilite/grand-livre', 'api/comptabilite/etats/bilan', 'api/comptabilite/etats/resultat',
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

    /** Routes rattachées à une autre règle que celle de leur préfixe (dont les actions nommées). */
    private const PARTICULIERES = [
        'PUT api/approbations/settings' => ['configuration', 'modifier'],
        'PUT api/clients/{client}/liste-prix' => ['gestion_prix', 'modifier'],
        'POST api/factures/commande/{commandeId}' => ['factures', 'creer'],
        'POST api/factures/{id}/creer-bl' => ['bons_livraison', 'creer'],
        'POST api/factures/{id}/emettre' => ['factures', 'emettre'],
        'POST api/factures/{id}/paiement' => ['factures', 'encaisser'],
        'POST api/avoirs/{id}/emettre' => ['avoirs', 'emettre'],
        'POST api/avoirs/{id}/imputer' => ['avoirs', 'regler'],
        'POST api/avoirs/{id}/rembourser' => ['avoirs', 'regler'],
        'POST api/commandes-client/{id}/soumettre' => ['commandes_client', 'soumettre'],
        'POST api/commandes-client/{id}/accepter' => ['commandes_client', 'decider'],
        'POST api/commandes-client/{id}/refuser' => ['commandes_client', 'decider'],
        'POST api/devis/{devis}/envoyer' => ['devis', 'envoyer'],
        'POST api/devis/{devis}/accepter' => ['devis', 'decider'],
        'POST api/devis/{devis}/refuser' => ['devis', 'decider'],
        'POST api/devis/{devis}/convertir' => ['devis', 'convertir'],
        'POST api/bons-livraison/{id}/preparer' => ['bons_livraison', 'preparer'],
        'POST api/bons-livraison/{id}/pret' => ['bons_livraison', 'preparer'],
        'POST api/bons-livraison/{id}/livrer' => ['bons_livraison', 'livrer'],
        'POST api/bons-livraison/{id}/annuler' => ['bons_livraison', 'annuler'],
        'POST api/commandes-fournisseur/{commande}/valider' => ['commandes_fournisseur', 'valider'],
        'POST api/commandes-fournisseur/{commande}/cloturer' => ['commandes_fournisseur', 'cloturer'],
        'POST api/commandes-fournisseur/{commande}/annuler' => ['commandes_fournisseur', 'annuler'],
        'POST api/approbations/{id}/approve' => ['approbations', 'decider'],
        'POST api/approbations/{id}/reject' => ['approbations', 'decider'],
        'POST api/transferts-planifies/{id}/execute' => ['planification', 'executer'],
        'POST api/transferts-planifies/{id}/cancel' => ['planification', 'executer'],
        'POST api/tournees/{id}/ajouter-bon' => ['tournees', 'piloter'],
        'POST api/tournees/{id}/demarrer' => ['tournees', 'piloter'],
        'POST api/tournees/{id}/terminer' => ['tournees', 'piloter'],
        'PUT api/tournees/{id}/ordre' => ['tournees', 'piloter'],
        'POST api/prises-inventaire' => ['inventaires', 'prises'],
        'PUT api/prises-inventaire/{id}/corrections' => ['inventaires', 'prises'],
        'POST api/prises-inventaire/{id}/valider' => ['inventaires', 'prises'],
        'POST api/prises-inventaire/{id}/annuler' => ['inventaires', 'prises'],
        'POST api/fournisseurs/{fournisseur}/comptes-bancaires' => ['fournisseurs', 'comptes_bancaires'],
        'PUT api/fournisseurs/{fournisseur}/comptes-bancaires/{id}' => ['fournisseurs', 'comptes_bancaires'],
        'DELETE api/fournisseurs/{fournisseur}/comptes-bancaires/{id}' => ['fournisseurs', 'comptes_bancaires'],
        'GET api/prises-inventaire/valorisation' => ['valorisation', 'voir'],
    ];

    private const VERBES = ['voir' => 'consulter', 'creer' => 'créer', 'modifier' => 'modifier', 'supprimer' => 'supprimer'];

    /** Permissions effectives déjà calculées pendant la requête : id du compte => code => accordée. */
    private array $memo = [];

    /**
     * Rôle principal d'un compte : rôle personnalisé actif de son entreprise, sinon rôle système de même nom.
     * Un administrateur garde le rôle système « admin » (tous les droits), même avec un rôle personnalisé.
     */
    public function role(AdminUser $compte): ?object
    {
        if ($compte->role !== 'admin' && ! empty($compte->role_custom_id)) {
            $personnalise = DB::table('roles_custom')->where('id', $compte->role_custom_id)->where('actif', true)
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

    /** Groupes du compte : son rôle principal, puis ses groupes supplémentaires actifs de son entreprise. */
    public function groupes(AdminUser $compte): Collection
    {
        $supplementaires = DB::table('admin_user_groupes')
            ->join('roles_custom', 'roles_custom.id', '=', 'admin_user_groupes.role_id')
            ->where('admin_user_groupes.admin_user_id', $compte->id)
            ->where('roles_custom.tenant_id', $compte->tenant_id)->where('roles_custom.actif', true)
            ->orderBy('roles_custom.nom')
            ->get(['roles_custom.*']);

        return collect([$this->role($compte)])->filter()->merge($supplementaires)->unique('id')->values();
    }

    /** Droits venant des seuls groupes (sans les exceptions du compte) : code => accordé. */
    public function droitsDesGroupes(AdminUser $compte): array
    {
        $ids = $this->groupes($compte)->pluck('id')->all();
        $droits = array_fill_keys(Permissions::codes(), false);
        $lignes = DB::table('role_permissions')->whereIn('role_id', $ids)->get()->groupBy('role_id');
        $reglees = DB::table('role_actions')->whereIn('role_id', $ids)->get()->groupBy('role_id');

        foreach ($ids as $id) {
            $parModule = ($lignes[$id] ?? collect())->keyBy('module');
            $explicites = ($reglees[$id] ?? collect())->mapWithKeys(fn ($r) => [$r->permission => (bool) $r->accorde]);
            foreach (Permissions::MODULES as $module => $definition) {
                $ligne = $parModule[$module] ?? null;
                foreach (self::COLONNES as $action => $colonne) {
                    if ($ligne && $ligne->{$colonne}) {
                        $droits["{$module}.{$action}"] = true;
                    }
                }
                foreach ($definition['actions'] ?? [] as $action => $nommee) {
                    $code = "{$module}.{$action}";
                    $accorde = $explicites->has($code) ? $explicites[$code] : (bool) ($ligne?->{self::COLONNES[$nommee['base']]} ?? false);
                    if ($accorde) {
                        $droits[$code] = true;
                    }
                }
            }
        }

        return $droits;
    }

    /** Exceptions du compte : code => accordée (true) ou refusée (false). */
    public function exceptions(AdminUser $compte): array
    {
        return DB::table('admin_user_permissions')->where('admin_user_id', $compte->id)->get()
            ->filter(fn ($e) => Permissions::existe($e->permission))
            ->mapWithKeys(fn ($e) => [$e->permission => (bool) $e->accorde])->all();
    }

    /** Permissions effectives du compte : code => accordée. Un administrateur a tout. */
    public function effectives(AdminUser $compte): array
    {
        if ($compte->role === 'admin') {
            return array_fill_keys(Permissions::codes(), true);
        }

        return $this->memo[$compte->id] ??= array_merge($this->droitsDesGroupes($compte), $this->exceptions($compte));
    }

    /** Oublie les droits calculés pendant la requête (après une modification des accès). */
    public function oublier(): void
    {
        $this->memo = [];
    }

    /** Le compte peut-il faire cette action (voir, creer, modifier, supprimer ou action nommée) dans ce module ? */
    public function permet(AdminUser $compte, string $module, string $action): bool
    {
        return $this->effectives($compte)["{$module}.{$action}"] ?? false;
    }

    /**
     * Module et action contrôlés pour une requête de l'API, ou null si elle n'est pas contrôlée par module :
     * toute écriture d'un préfixe connu, et les lectures des données sensibles.
     *
     * @return array{string, string}|null
     */
    public function regle(Request $request): ?array
    {
        $methode = $request->method();
        $uri = $request->route()?->uri() ?? $request->path();
        $cle = "{$methode} {$uri}";
        if (isset(self::PARTICULIERES[$cle])) {
            return self::PARTICULIERES[$cle];
        }
        $segments = explode('/', $uri);
        $prefixe = $segments[1] ?? '';

        if (in_array($methode, ['GET', 'HEAD', 'OPTIONS'], true)) {
            if ($methode === 'OPTIONS' || ! isset(self::LECTURES[$prefixe])) {
                return null;
            }
            if (in_array($uri, self::ETATS_COMPTABLES, true)) {
                return ['comptabilite', 'etats'];
            }

            return [self::LECTURES[$prefixe], 'voir'];
        }

        if (in_array($cle, self::LIBRES, true)) {
            return null;
        }
        if (isset(self::ECRITURES_ACTION[$prefixe])) {
            return self::ECRITURES_ACTION[$prefixe];
        }
        if ($prefixe === 'comptabilite') {
            return ['comptabilite', $this->actionComptable($uri)];
        }

        $module = self::MODULES[$prefixe] ?? null;
        if ($module === null) {
            return null;
        }

        // Supprimer, modifier (PUT, ou POST sur un élément existant), sinon créer.
        $action = match (true) {
            $methode === 'DELETE' => 'supprimer',
            $methode === 'PUT', $methode === 'PATCH', str_contains($uri, '{') => 'modifier',
            default => 'creer',
        };

        return [$module, $action];
    }

    /** Écriture comptable => action nommée : clôtures, plan et exercices, sinon écritures. */
    private function actionComptable(string $uri): string
    {
        return match (true) {
            str_ends_with($uri, '/cloturer'), str_ends_with($uri, '/rouvrir') => 'cloturer',
            str_starts_with($uri, 'api/comptabilite/comptes'), str_starts_with($uri, 'api/comptabilite/exercices'), str_ends_with($uri, '/fermer') => 'plan',
            default => 'ecrire',
        };
    }

    public function messageRefus(string $module, string $action): string
    {
        $libelle = Permissions::libelle($module);
        $nommee = Permissions::actions($module)[$action]['fr'] ?? null;
        if ($nommee !== null) {
            return "Droit insuffisant : votre rôle ne permet pas de faire « {$nommee} » ({$libelle}).";
        }

        return "Droit insuffisant : votre rôle ne permet pas de {$this->verbe($action)} dans « {$libelle} ».";
    }

    private function verbe(string $action): string
    {
        return self::VERBES[$action] ?? $action;
    }
}
