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
        'etiquettes' => 'etiquettes',
        'lots' => 'lots',
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
        'suggestions-achat' => 'suggestions_achat',
        'receptions' => 'receptions',
        'retours-fournisseurs' => 'retours_fournisseurs',
        'factures-fournisseurs' => 'factures_fournisseurs',
        'debourses' => 'debourses',
        'banque' => 'banque',
        'budgets' => 'budgets',
        'devis' => 'devis',
        'clients' => 'clients',
        'commandes-client' => 'commandes_client',
        'reliquats-clients' => 'reliquats_clients',
        'abonnements-clients' => 'abonnements_clients',
        'contrats-location' => 'contrats_location',
        'consignations' => 'consignations',
        'factures' => 'factures',
        'avoirs' => 'avoirs',
        'retours-clients' => 'retours_clients',
        'acomptes' => 'acomptes',
        'encaissements' => 'encaissements',
        'demandes-acompte' => 'demandes_acompte',
        'caisses' => 'caisses',
        'point-de-vente' => 'point_de_vente',
        'cartes-cadeaux' => 'cartes_cadeaux',
        'relances' => 'relances',
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
        'frais' => 'configuration',
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
        // Banque : soldes, mouvements et transactions des comptes bancaires.
        'banque' => 'banque',
        // Budgets : montants prévus par compte ; la comparaison au réel demande aussi de voir les états comptables.
        'budgets' => 'budgets',
        'comptes-clients' => 'comptes_clients',
        // Encaissements des clients : l'argent reçu et sa répartition ; déboursés : l'argent versé aux fournisseurs.
        'encaissements' => 'encaissements',
        'debourses' => 'debourses',
        'relances' => 'relances',
        'portail-clients' => 'espace_client',
        // Sessions de caisse : montants des tiroirs.
        'caisses' => 'caisses',
        // Cartes cadeaux : soldes et mouvements.
        'cartes-cadeaux' => 'cartes_cadeaux',
        // Étiquettes : les formats, pour qui peut imprimer.
        'etiquettes' => 'etiquettes',
        // Lots et numéros de série : registre, péremptions, provenance.
        'lots' => 'lots',
        'numeros-serie' => 'lots',
    ];

    /** États comptables : il faut le droit de voir les états. */
    private const ETATS_COMPTABLES = [
        'api/comptabilite/balance', 'api/comptabilite/grand-livre', 'api/comptabilite/etats/bilan', 'api/comptabilite/etats/resultat',
        'api/comptabilite/etats/taxes',
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
        'POST api/frais/calculer',
        'POST api/secteurs/validate-qr',
        'POST api/configurations/{entite}/consommer',
        'POST api/courriels/modeles/apercu',
        'POST api/courriels/envois',
        'POST api/courriels/envois/{id}/reessayer',
        'PUT api/champs-personnalises/valeurs',
        'PUT api/balises/affectations',
        'POST api/clients/{client}/favori',
        'DELETE api/clients/{client}/favori',
        // Aperçu d'un abonnement avant de l'enregistrer : un calcul, rien n'est écrit.
        'POST api/abonnements-clients/apercu',
        // Aperçu d'une vente au comptoir (prix du client, taxes) : un calcul, rien n'est écrit.
        'POST api/point-de-vente/calculer',
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
        'POST api/retours-clients/{id}/traiter' => ['retours_clients', 'traiter'],
        'POST api/reliquats-clients/depuis-commande/{id}' => ['reliquats_clients', 'creer'],
        'POST api/reliquats-clients/commander' => ['reliquats_clients', 'commander'],
        'POST api/reliquats-clients/facturer' => ['reliquats_clients', 'facturer'],
        // Facturer les abonnements (créer les factures, aussi contrôlé par le contrôleur) ; relier une échéance.
        'POST api/abonnements-clients/facturer' => ['abonnements_clients', 'facturer'],
        'POST api/abonnements-clients/{id}/echeances/{echeanceId}/relier' => ['abonnements_clients', 'facturer'],
        // Relevés de consommation : modifier l'abonnement.
        'POST api/abonnements-clients/releves/import' => ['abonnements_clients', 'modifier'],
        'POST api/abonnements-clients/{id}/releves' => ['abonnements_clients', 'modifier'],
        'DELETE api/abonnements-clients/{id}/releves/{releveId}' => ['abonnements_clients', 'modifier'],
        // Contrats de location : facturer (prix de contrat, loyers ; droits des factures contrôlés aussi), renouveler.
        'POST api/contrats-location/{id}/facturer' => ['contrats_location', 'facturer'],
        'POST api/contrats-location/facturer-loyers' => ['contrats_location', 'facturer'],
        'POST api/contrats-location/{id}/renouveler' => ['contrats_location', 'renouveler'],
        // Consignation : facturer la consommation (droits des factures contrôlés aussi) ; reprendre de la marchandise.
        'POST api/consignations/facturer' => ['consignations', 'facturer'],
        'POST api/consignations/reprise' => ['consignations', 'modifier'],
        'POST api/retours-clients/{id}/crediter' => ['avoirs', 'creer'],
        'POST api/acomptes/{id}/appliquer' => ['acomptes', 'appliquer'],
        'POST api/acomptes/{id}/rembourser' => ['acomptes', 'rembourser'],
        'POST api/encaissements/{id}/annuler' => ['encaissements', 'annuler'],
        'POST api/demandes-acompte/{id}/envoyer' => ['demandes_acompte', 'envoyer'],
        'POST api/demandes-acompte/{id}/encaisser' => ['demandes_acompte', 'encaisser'],
        // Grilles de prix fournisseur : importer (la simulation aussi) ; profils d'import (seul type : prix fournisseur).
        'POST api/fournisseurs/import-prix' => ['fournisseurs', 'importer_prix'],
        'POST api/profils-import' => ['fournisseurs', 'importer_prix'],
        'DELETE api/profils-import/{id}' => ['fournisseurs', 'importer_prix'],
        // Suggestions d'achat : en signaler une (depuis un produit) ; calculer, accepter, modifier, retirer et commander (les
        // droits des commandes fournisseur sont contrôlés aussi pour commander).
        'POST api/suggestions-achat' => ['suggestions_achat', 'suggerer'],
        'POST api/suggestions-achat/generer' => ['suggestions_achat', 'gerer'],
        'POST api/suggestions-achat/accepter' => ['suggestions_achat', 'gerer'],
        'POST api/suggestions-achat/commander' => ['suggestions_achat', 'gerer'],
        'PUT api/suggestions-achat/{id}' => ['suggestions_achat', 'gerer'],
        'DELETE api/suggestions-achat/{id}' => ['suggestions_achat', 'gerer'],
        // Cartes cadeaux : recharger (modifier) ; rembourser le solde (le droit de modifier est contrôlé aussi).
        'POST api/cartes-cadeaux/{id}/recharger' => ['cartes_cadeaux', 'modifier'],
        'POST api/cartes-cadeaux/{id}/rembourser' => ['cartes_cadeaux', 'rembourser'],
        // Vente au comptoir (droits des factures contrôlés aussi par le contrôleur).
        'POST api/point-de-vente/ventes' => ['point_de_vente', 'vendre'],
        // Gestion de caisse : ouvrir, entrées et sorties d'argent, fermer.
        'POST api/caisses/{id}/ouvrir' => ['caisses', 'gerer'],
        'POST api/caisses/sessions/{id}/mouvements' => ['caisses', 'gerer'],
        'POST api/caisses/sessions/{id}/fermer' => ['caisses', 'gerer'],
        'POST api/factures/{id}/relances' => ['relances', 'creer'],
        'POST api/commandes-client/{id}/soumettre' => ['commandes_client', 'soumettre'],
        'POST api/commandes-client/{id}/accepter' => ['commandes_client', 'decider'],
        'POST api/commandes-client/{id}/refuser' => ['commandes_client', 'decider'],
        'POST api/devis/{devis}/envoyer' => ['devis', 'envoyer'],
        'POST api/devis/{devis}/accepter' => ['devis', 'decider'],
        'POST api/devis/{devis}/refuser' => ['devis', 'decider'],
        'POST api/devis/{devis}/convertir' => ['devis', 'convertir'],
        'POST api/devis/{devis}/lien-reponse' => ['devis', 'envoyer'],
        // Facturer un devis demande aussi le droit de créer une facture (vérifié par le contrôleur).
        'POST api/devis/{devis}/facturer' => ['devis', 'convertir'],
        'POST api/devis/{devis}/copier' => ['devis', 'creer'],
        'POST api/bons-livraison/{id}/preparer' => ['bons_livraison', 'preparer'],
        'POST api/bons-livraison/{id}/pret' => ['bons_livraison', 'preparer'],
        'POST api/bons-livraison/{id}/livrer' => ['bons_livraison', 'livrer'],
        'POST api/bons-livraison/{id}/annuler' => ['bons_livraison', 'annuler'],
        // Les colis se préparent avec le droit de préparer les bons.
        'POST api/bons-livraison/{id}/colis' => ['bons_livraison', 'preparer'],
        'PUT api/bons-livraison/{id}/colis/{colisId}' => ['bons_livraison', 'preparer'],
        'DELETE api/bons-livraison/{id}/colis/{colisId}' => ['bons_livraison', 'preparer'],
        'POST api/commandes-fournisseur/{commande}/valider' => ['commandes_fournisseur', 'valider'],
        'POST api/commandes-fournisseur/{commande}/cloturer' => ['commandes_fournisseur', 'cloturer'],
        'POST api/commandes-fournisseur/{commande}/annuler' => ['commandes_fournisseur', 'annuler'],
        'POST api/retours-fournisseurs/{id}/expedier' => ['retours_fournisseurs', 'expedier'],
        'POST api/receptions/documents/{id}/annuler' => ['receptions', 'annuler'],
        'POST api/factures-fournisseurs/{id}/aligner-couts' => ['factures_fournisseurs', 'modifier'],
        'POST api/factures-fournisseurs/{id}/paiements' => ['factures_fournisseurs', 'payer'],
        'DELETE api/factures-fournisseurs/{id}/paiements/{paiement}' => ['factures_fournisseurs', 'payer'],
        'POST api/factures-fournisseurs/{id}/annuler' => ['factures_fournisseurs', 'annuler'],
        'POST api/debourses/{id}/annuler' => ['debourses', 'annuler'],
        // Banque : relier un compte bancaire à son compte comptable ; saisir et annuler une transaction bancaire.
        'PUT api/banque/comptes/{id}' => ['banque', 'configurer'],
        'POST api/banque/transactions' => ['banque', 'saisir'],
        'POST api/banque/transactions/{id}/annuler' => ['banque', 'annuler'],
        // Rapprochement bancaire : commencer, corriger le relevé, pointer, terminer, rouvrir, supprimer.
        'POST api/banque/comptes/{id}/rapprochements' => ['banque', 'rapprocher'],
        'PUT api/banque/rapprochements/{id}' => ['banque', 'rapprocher'],
        'POST api/banque/rapprochements/{id}/pointer' => ['banque', 'rapprocher'],
        'POST api/banque/rapprochements/{id}/terminer' => ['banque', 'rapprocher'],
        'POST api/banque/rapprochements/{id}/rouvrir' => ['banque', 'rapprocher'],
        'DELETE api/banque/rapprochements/{id}' => ['banque', 'rapprocher'],
        'POST api/banque/rapprochements/{id}/pointer-releve' => ['banque', 'rapprocher'],
        // Relevés importés : importer, relier, délier, exclure, rétablir (comme le rapprochement) ; classer crée une
        // transaction bancaire.
        'POST api/banque/comptes/{id}/releves' => ['banque', 'rapprocher'],
        'DELETE api/banque/releves/{releve}' => ['banque', 'rapprocher'],
        'POST api/debourses/cheques/numeroter' => ['debourses', 'cheques'],
        // Codes-barres : ajouter et retirer modifient le produit.
        'POST api/produits/{produit}/codes-barres' => ['produits', 'modifier'],
        'DELETE api/produits/{produit}/codes-barres/{id}' => ['produits', 'modifier'],
        // Unités de vente : les ajouter, les changer ou les retirer modifie le produit.
        'POST api/produits/{produit}/unites' => ['produits', 'modifier'],
        'DELETE api/produits/{produit}/unites/{id}' => ['produits', 'modifier'],
        // Liens (composants, annexes, équivalents) : les ajouter ou les retirer modifie le produit.
        'POST api/produits/{produit}/liens' => ['produits', 'modifier'],
        'DELETE api/produits/{produit}/liens/{id}' => ['produits', 'modifier'],
        // Étiquettes : imprimer n'écrit rien (droit de les voir) ; les formats se gèrent avec l'action nommée.
        'POST api/etiquettes/imprimer' => ['etiquettes', 'voir'],
        'POST api/etiquettes/formats' => ['etiquettes', 'formats'],
        'PUT api/etiquettes/formats/{id}' => ['etiquettes', 'formats'],
        'DELETE api/etiquettes/formats/{id}' => ['etiquettes', 'formats'],
        // Virements : générer un lot, le télécharger (coordonnées bancaires), le marquer refusé.
        'POST api/debourses/virements/lots' => ['debourses', 'virements'],
        'GET api/debourses/virements/lots/{id}/fichier' => ['debourses', 'virements'],
        'POST api/debourses/virements/lots/{id}/refuser' => ['debourses', 'virements'],
        'POST api/banque/comptes/{id}/releve/relier-suggestions' => ['banque', 'rapprocher'],
        'POST api/banque/releve-lignes/{ligne}/relier' => ['banque', 'rapprocher'],
        'POST api/banque/releve-lignes/{ligne}/delier' => ['banque', 'rapprocher'],
        'POST api/banque/releve-lignes/{ligne}/exclure' => ['banque', 'rapprocher'],
        'POST api/banque/releve-lignes/{ligne}/retablir' => ['banque', 'rapprocher'],
        'POST api/banque/releve-lignes/{ligne}/classer' => ['banque', 'saisir'],
        // Budgets : saisir des cellules, répartir, copier, choisir les comptes budgétés.
        'PUT api/budgets' => ['budgets', 'saisir'],
        'POST api/budgets/repartir' => ['budgets', 'saisir'],
        'POST api/budgets/copier' => ['budgets', 'saisir'],
        'PUT api/budgets/comptes' => ['budgets', 'saisir'],
        'POST api/retours-fournisseurs/{id}/cloturer' => ['retours_fournisseurs', 'cloturer'],
        'POST api/approbations/{id}/approve' => ['approbations', 'decider'],
        'POST api/approbations/{id}/reject' => ['approbations', 'decider'],
        'POST api/transferts-planifies/{id}/execute' => ['planification', 'executer'],
        'POST api/transferts-planifies/{id}/cancel' => ['planification', 'executer'],
        'POST api/tournees/{id}/ajouter-bon' => ['tournees', 'piloter'],
        'POST api/tournees/{id}/demarrer' => ['tournees', 'piloter'],
        'POST api/tournees/{id}/terminer' => ['tournees', 'piloter'],
        'PUT api/tournees/{id}/ordre' => ['tournees', 'piloter'],
        'POST api/tournees/{id}/ordonner' => ['tournees', 'piloter'],
        'POST api/tournees/{id}/ajouter-route' => ['tournees', 'piloter'],
        // Chargement du camion : cocher les colis et le vrac, scanner les étiquettes.
        'POST api/tournees/{id}/chargement/scan' => ['tournees', 'charger'],
        'POST api/tournees/{id}/chargement/colis/{colisId}' => ['tournees', 'charger'],
        'POST api/tournees/{id}/chargement/vrac/{bonId}' => ['tournees', 'charger'],
        'POST api/prises-inventaire' => ['inventaires', 'prises'],
        'PUT api/prises-inventaire/{id}/corrections' => ['inventaires', 'prises'],
        'POST api/prises-inventaire/{id}/valider' => ['inventaires', 'prises'],
        'POST api/prises-inventaire/{id}/annuler' => ['inventaires', 'prises'],
        'POST api/fournisseurs/{fournisseur}/comptes-bancaires' => ['fournisseurs', 'comptes_bancaires'],
        'PUT api/fournisseurs/{fournisseur}/comptes-bancaires/{id}' => ['fournisseurs', 'comptes_bancaires'],
        'DELETE api/fournisseurs/{fournisseur}/comptes-bancaires/{id}' => ['fournisseurs', 'comptes_bancaires'],
        'GET api/prises-inventaire/valorisation' => ['valorisation', 'voir'],
        // Stock par entrepôt (multi-entrepôts).
        'GET api/stock-entrepots' => ['inventaires', 'voir'],
        // Bibliothèque des rapports : le droit de voir les rapports, puis celui du module de chaque rapport (contrôleur).
        'GET api/rapports/bibliotheque' => ['rapports', 'voir'],
        'GET api/rapports/bibliotheque/{code}' => ['rapports', 'voir'],
        // Envoi d'un rapport par courriel, et envois programmés (consulter avec le droit de voir les rapports).
        'GET api/rapports/bibliotheque/{code}/courriel' => ['rapports', 'envoyer'],
        'POST api/rapports/bibliotheque/{code}/courriel' => ['rapports', 'envoyer'],
        'GET api/rapports/programmes' => ['rapports', 'voir'],
        'POST api/rapports/programmes' => ['rapports', 'programmer'],
        'PUT api/rapports/programmes/{id}' => ['rapports', 'programmer'],
        'DELETE api/rapports/programmes/{id}' => ['rapports', 'programmer'],
        'POST api/rapports/programmes/{id}/envoyer' => ['rapports', 'programmer'],
        // Questions à l'IA sur les rapports (chaque compte ne voit que les siennes).
        'GET api/rapports/ia/questions' => ['rapports', 'questionner'],
        'POST api/rapports/ia/questions' => ['rapports', 'questionner'],
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
        // L'application mobile reprend sous « mobile/ » des adresses du back-office : elle suit les mêmes droits.
        if (str_starts_with($uri, 'api/mobile/')) {
            $uri = 'api/'.substr($uri, strlen('api/mobile/'));
        }
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
