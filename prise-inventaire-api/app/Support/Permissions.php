<?php

namespace App\Support;

/**
 * Catalogue des permissions, sur le modèle de fripv2 : par onglet du menu, chaque module (écran) a ses droits
 * de base (voir, créer, modifier, supprimer) et des actions nommées (émettre une facture, clôturer un
 * exercice…). Une action nommée qu'un groupe ne règle pas suit le droit de base indiqué (« base ») : sans
 * réglage, rien ne change par rapport aux droits par module.
 *
 * Code d'une permission : « module.action » (ex. « factures.emettre », « clients.voir »).
 */
class Permissions
{
    /** Droits de base, dans l'ordre d'affichage. */
    public const CRUD = ['voir', 'creer', 'modifier', 'supprimer'];

    public const ONGLETS = [
        'general' => ['fr' => 'Général', 'en' => 'General'],
        'inventaire' => ['fr' => 'Inventaire', 'en' => 'Inventory'],
        'achats' => ['fr' => 'Achats', 'en' => 'Purchasing'],
        'ventes' => ['fr' => 'Ventes', 'en' => 'Sales'],
        'finance' => ['fr' => 'Finance', 'en' => 'Finance'],
        'parametres' => ['fr' => 'Paramètres', 'en' => 'Settings'],
    ];

    /**
     * Modules : onglet, libellés, droits de base utiles (« crud », tous par défaut) et actions nommées.
     *
     * @var array<string, array{onglet: string, fr: string, en: string, crud?: list<string>, actions?: array<string, array{base: string, fr: string, en: string, aide_fr: string, aide_en: string}>}>
     */
    public const MODULES = [
        'dashboard' => ['onglet' => 'general', 'fr' => 'Tableau de bord', 'en' => 'Dashboard', 'crud' => ['voir']],

        'inventaires' => ['onglet' => 'inventaire', 'fr' => 'Inventaires et scans', 'en' => 'Stock counts and scans', 'actions' => [
            'prises' => ['base' => 'modifier', 'fr' => "Gérer les prises d'inventaire", 'en' => 'Manage stocktakes',
                'aide_fr' => "Créer une prise d'inventaire, saisir les corrections, la valider (elle devient l'inventaire officiel) ou l'annuler.",
                'aide_en' => 'Create a stocktake, enter corrections, validate it (it becomes the official inventory) or cancel it.'],
        ]],
        'valorisation' => ['onglet' => 'inventaire', 'fr' => "Valorisation de l'inventaire", 'en' => 'Inventory valuation', 'crud' => ['voir']],
        'statistiques' => ['onglet' => 'inventaire', 'fr' => 'Statistiques', 'en' => 'Statistics', 'crud' => ['voir']],
        'comparaison' => ['onglet' => 'inventaire', 'fr' => 'Comparaison', 'en' => 'Comparison', 'crud' => ['voir']],
        'alertes' => ['onglet' => 'inventaire', 'fr' => 'Alertes de stock', 'en' => 'Stock alerts'],
        'historique' => ['onglet' => 'inventaire', 'fr' => 'Historique', 'en' => 'History', 'crud' => ['voir']],
        'tracabilite' => ['onglet' => 'inventaire', 'fr' => 'Traçabilité', 'en' => 'Traceability', 'crud' => ['voir']],
        'relocalisation' => ['onglet' => 'inventaire', 'fr' => 'Relocalisation', 'en' => 'Relocation'],
        'planification' => ['onglet' => 'inventaire', 'fr' => 'Planification des transferts', 'en' => 'Transfer planning', 'actions' => [
            'executer' => ['base' => 'modifier', 'fr' => 'Exécuter ou annuler un transfert planifié', 'en' => 'Run or cancel a planned transfer',
                'aide_fr' => "Lancer tout de suite un transfert planifié, ou l'annuler.",
                'aide_en' => 'Run a planned transfer right away, or cancel it.'],
        ]],
        'approbations' => ['onglet' => 'inventaire', 'fr' => 'Approbations', 'en' => 'Approvals', 'actions' => [
            'decider' => ['base' => 'modifier', 'fr' => 'Approuver ou rejeter une demande', 'en' => 'Approve or reject a request',
                'aide_fr' => 'Approuver ou rejeter un mouvement en attente au-delà des seuils.',
                'aide_en' => 'Approve or reject a pending movement above the thresholds.'],
        ]],
        'rapports' => ['onglet' => 'inventaire', 'fr' => 'Rapports', 'en' => 'Reports', 'crud' => ['voir']],
        'inventaire_tournant' => ['onglet' => 'inventaire', 'fr' => 'Inventaire tournant', 'en' => 'Cycle counting'],

        'fournisseurs' => ['onglet' => 'achats', 'fr' => 'Fournisseurs', 'en' => 'Suppliers', 'actions' => [
            'comptes_bancaires' => ['base' => 'modifier', 'fr' => 'Gérer les coordonnées bancaires', 'en' => 'Manage bank details',
                'aide_fr' => "Ajouter, modifier ou supprimer les comptes bancaires d'un fournisseur (fiche fournisseur).",
                'aide_en' => "Add, change or delete a supplier's bank accounts (supplier record)."],
        ]],
        'commandes_fournisseur' => ['onglet' => 'achats', 'fr' => 'Commandes fournisseur', 'en' => 'Purchase orders', 'actions' => [
            'valider' => ['base' => 'modifier', 'fr' => 'Valider une commande', 'en' => 'Confirm an order',
                'aide_fr' => 'Valider une commande fournisseur (elle part chez le fournisseur).',
                'aide_en' => 'Confirm a purchase order (it is sent to the supplier).'],
            'cloturer' => ['base' => 'modifier', 'fr' => 'Clôturer une commande', 'en' => 'Close an order',
                'aide_fr' => 'Clôturer une commande dont le reste ne sera pas livré.',
                'aide_en' => 'Close an order whose remainder will not be delivered.'],
            'annuler' => ['base' => 'modifier', 'fr' => 'Annuler une commande', 'en' => 'Cancel an order',
                'aide_fr' => 'Annuler une commande fournisseur.', 'aide_en' => 'Cancel a purchase order.'],
        ]],
        'receptions' => ['onglet' => 'achats', 'fr' => 'Réceptions', 'en' => 'Receipts'],

        'clients' => ['onglet' => 'ventes', 'fr' => 'Clients', 'en' => 'Customers', 'actions' => [
            'categories' => ['base' => 'supprimer', 'fr' => 'Gérer les catégories de clients', 'en' => 'Manage customer categories',
                'aide_fr' => 'Créer, renommer ou supprimer les catégories de clients (fiche client).',
                'aide_en' => 'Create, rename or delete customer categories (customer record).'],
        ]],
        'espace_client' => ['onglet' => 'ventes', 'fr' => 'Espace client', 'en' => 'Customer area'],
        'devis' => ['onglet' => 'ventes', 'fr' => 'Devis', 'en' => 'Quotes', 'actions' => [
            'envoyer' => ['base' => 'modifier', 'fr' => 'Envoyer un devis', 'en' => 'Send a quote',
                'aide_fr' => 'Marquer un devis comme envoyé au client.', 'aide_en' => 'Mark a quote as sent to the customer.'],
            'decider' => ['base' => 'modifier', 'fr' => 'Accepter ou refuser un devis', 'en' => 'Accept or decline a quote',
                'aide_fr' => 'Enregistrer la réponse du client : devis accepté ou refusé.',
                'aide_en' => "Record the customer's answer: quote accepted or declined."],
            'convertir' => ['base' => 'modifier', 'fr' => 'Convertir un devis en commande', 'en' => 'Convert a quote to an order',
                'aide_fr' => 'Créer la commande client à partir d\'un devis accepté.',
                'aide_en' => 'Create the customer order from an accepted quote.'],
        ]],
        'commandes_client' => ['onglet' => 'ventes', 'fr' => 'Commandes client', 'en' => 'Customer orders', 'actions' => [
            'soumettre' => ['base' => 'modifier', 'fr' => 'Soumettre une commande', 'en' => 'Submit an order',
                'aide_fr' => 'Passer une commande brouillon « en attente » de décision.',
                'aide_en' => 'Move a draft order to "pending" for a decision.'],
            'decider' => ['base' => 'modifier', 'fr' => 'Accepter ou refuser une commande', 'en' => 'Accept or decline an order',
                'aide_fr' => "Accepter une commande en attente (dont celles de l'espace client) ou la refuser avec un motif.",
                'aide_en' => 'Accept a pending order (including customer area orders) or decline it with a reason.'],
        ]],
        'factures' => ['onglet' => 'ventes', 'fr' => 'Factures', 'en' => 'Invoices', 'actions' => [
            'emettre' => ['base' => 'modifier', 'fr' => 'Émettre une facture', 'en' => 'Issue an invoice',
                'aide_fr' => 'Émettre une facture brouillon : elle reçoit son numéro définitif et devient due.',
                'aide_en' => 'Issue a draft invoice: it gets its final number and becomes due.'],
            'encaisser' => ['base' => 'modifier', 'fr' => 'Enregistrer un paiement', 'en' => 'Record a payment',
                'aide_fr' => 'Enregistrer un paiement reçu sur une facture.', 'aide_en' => 'Record a payment received on an invoice.'],
        ]],
        'avoirs' => ['onglet' => 'ventes', 'fr' => 'Notes de crédit', 'en' => 'Credit notes', 'actions' => [
            'emettre' => ['base' => 'modifier', 'fr' => 'Émettre une note de crédit', 'en' => 'Issue a credit note',
                'aide_fr' => 'Émettre une note de crédit brouillon : elle reçoit son numéro et réduit ce qui reste dû de sa facture.',
                'aide_en' => 'Issue a draft credit note: it gets its number and reduces the amount still due on its invoice.'],
            'regler' => ['base' => 'modifier', 'fr' => 'Rembourser ou imputer un crédit', 'en' => 'Refund or apply a credit',
                'aide_fr' => "Rembourser au client le crédit disponible d'une note, ou l'imputer sur une autre de ses factures.",
                'aide_en' => "Refund a credit note's available credit to the customer, or apply it to another of their invoices."],
        ]],
        'acomptes' => ['onglet' => 'ventes', 'fr' => 'Acomptes', 'en' => 'Deposits', 'actions' => [
            'appliquer' => ['base' => 'modifier', 'fr' => 'Appliquer un acompte sur une facture', 'en' => 'Apply a deposit to an invoice',
                'aide_fr' => "Utiliser ce qui reste d'un acompte pour payer une facture du client.",
                'aide_en' => "Use what is left of a deposit to pay one of the customer's invoices."],
            'rembourser' => ['base' => 'modifier', 'fr' => 'Rembourser un acompte', 'en' => 'Refund a deposit',
                'aide_fr' => "Rendre au client tout ou partie de ce qui reste d'un acompte.",
                'aide_en' => 'Give back to the customer all or part of what is left of a deposit.'],
        ]],
        'demandes_acompte' => ['onglet' => 'ventes', 'fr' => "Demandes d'acompte", 'en' => 'Deposit requests', 'actions' => [
            'envoyer' => ['base' => 'modifier', 'fr' => "Marquer une demande d'acompte envoyée", 'en' => 'Mark a deposit request as sent',
                'aide_fr' => 'Marquer une demande comme envoyée au client (son envoi par courriel le fait aussi) : elle ne se modifie plus.',
                'aide_en' => 'Mark a request as sent to the customer (sending it by email does it too): it can no longer be changed.'],
            'encaisser' => ['base' => 'modifier', 'fr' => "Marquer une demande d'acompte payée", 'en' => 'Mark a deposit request as paid',
                'aide_fr' => "Enregistrer le dépôt reçu (date, mode, référence) : l'acompte est créé au dossier du client.",
                'aide_en' => "Record the deposit received (date, method, reference): the deposit is added to the customer's account."],
        ]],
        'comptes_clients' => ['onglet' => 'ventes', 'fr' => 'Comptes clients', 'en' => 'Receivables', 'crud' => ['voir']],
        'relances' => ['onglet' => 'ventes', 'fr' => 'Relances de paiement', 'en' => 'Payment reminders', 'crud' => ['voir', 'creer']],
        'bons_livraison' => ['onglet' => 'ventes', 'fr' => 'Bons de livraison', 'en' => 'Delivery notes', 'actions' => [
            'preparer' => ['base' => 'modifier', 'fr' => 'Préparer un bon de livraison', 'en' => 'Prepare a delivery note',
                'aide_fr' => 'Passer un bon en préparation, puis le marquer prêt.', 'aide_en' => 'Start preparing a note, then mark it ready.'],
            'livrer' => ['base' => 'modifier', 'fr' => 'Confirmer une livraison', 'en' => 'Confirm a delivery',
                'aide_fr' => 'Marquer un bon comme livré.', 'aide_en' => 'Mark a note as delivered.'],
            'annuler' => ['base' => 'modifier', 'fr' => 'Annuler un bon de livraison', 'en' => 'Cancel a delivery note',
                'aide_fr' => 'Annuler un bon de livraison.', 'aide_en' => 'Cancel a delivery note.'],
        ]],
        'camions' => ['onglet' => 'ventes', 'fr' => 'Camions', 'en' => 'Trucks'],
        'tournees' => ['onglet' => 'ventes', 'fr' => 'Tournées', 'en' => 'Delivery runs', 'actions' => [
            'piloter' => ['base' => 'modifier', 'fr' => 'Piloter une tournée', 'en' => 'Run a delivery run',
                'aide_fr' => "Ajouter des bons, fixer l'ordre de passage, démarrer et terminer une tournée.",
                'aide_en' => 'Add notes, set the stop order, start and finish a run.'],
        ]],
        'zones_preparation' => ['onglet' => 'ventes', 'fr' => 'Zones de préparation', 'en' => 'Picking areas'],

        'comptabilite' => ['onglet' => 'finance', 'fr' => 'Comptabilité', 'en' => 'Accounting', 'crud' => ['voir'], 'actions' => [
            'etats' => ['base' => 'voir', 'fr' => 'Voir les états comptables', 'en' => 'View financial statements',
                'aide_fr' => 'Ouvrir la balance, le grand livre, le bilan et le compte de résultat.',
                'aide_en' => 'Open the trial balance, general ledger, balance sheet and income statement.'],
            'ecrire' => ['base' => 'creer', 'fr' => 'Saisir des écritures', 'en' => 'Post journal entries',
                'aide_fr' => "Saisir, modifier, supprimer et contre-passer des écritures ; gérer les journaux et les soldes d'ouverture.",
                'aide_en' => 'Post, change, delete and reverse entries; manage journals and opening balances.'],
            'plan' => ['base' => 'modifier', 'fr' => 'Modifier le plan comptable et les exercices', 'en' => 'Change the chart of accounts and fiscal years',
                'aide_fr' => 'Créer, modifier, importer et supprimer des comptes ; créer et modifier des exercices ; fermer une période.',
                'aide_en' => 'Create, change, import and delete accounts; create and change fiscal years; close a period.'],
            'cloturer' => ['base' => 'supprimer', 'fr' => 'Clôturer ou rouvrir un exercice', 'en' => 'Close or reopen a fiscal year',
                'aide_fr' => 'Clôturer un exercice (report du résultat) ou le rouvrir, et rouvrir une période fermée.',
                'aide_en' => 'Close a fiscal year (carry the result forward) or reopen it, and reopen a closed period.'],
        ]],
        'previsions_stock' => ['onglet' => 'finance', 'fr' => 'Prévisions de stock', 'en' => 'Stock forecasts', 'crud' => ['voir']],
        'gestion_prix' => ['onglet' => 'finance', 'fr' => 'Tarifs et prix', 'en' => 'Prices'],

        'produits' => ['onglet' => 'parametres', 'fr' => 'Produits', 'en' => 'Products'],
        'secteurs' => ['onglet' => 'parametres', 'fr' => 'Secteurs', 'en' => 'Sectors'],
        'employes' => ['onglet' => 'parametres', 'fr' => 'Employés', 'en' => 'Employees'],
        'fiches_employes' => ['onglet' => 'parametres', 'fr' => 'Fiches employés', 'en' => 'Employee records'],
        'roles' => ['onglet' => 'parametres', 'fr' => 'Rôles et permissions', 'en' => 'Roles and permissions', 'crud' => ['voir']],
        'referentiels' => ['onglet' => 'parametres', 'fr' => 'Référentiels', 'en' => 'Reference lists'],
        'alertes_config' => ['onglet' => 'parametres', 'fr' => 'Réglage des alertes', 'en' => 'Alert settings', 'crud' => ['voir', 'modifier']],
        'configuration' => ['onglet' => 'parametres', 'fr' => 'Configuration', 'en' => 'Configuration'],
    ];

    private const LIBELLES_CRUD = [
        'voir' => ['fr' => 'Consulter', 'en' => 'View'],
        'creer' => ['fr' => 'Créer', 'en' => 'Create'],
        'modifier' => ['fr' => 'Modifier', 'en' => 'Edit'],
        'supprimer' => ['fr' => 'Supprimer', 'en' => 'Delete'],
    ];

    /** Clés des modules. */
    public static function modules(): array
    {
        return array_keys(self::MODULES);
    }

    /** Droits de base d'un module. */
    public static function crud(string $module): array
    {
        return self::MODULES[$module]['crud'] ?? self::CRUD;
    }

    /** Actions nommées d'un module : action => définition. */
    public static function actions(string $module): array
    {
        return self::MODULES[$module]['actions'] ?? [];
    }

    /** Tous les codes, droits de base compris. */
    public static function codes(): array
    {
        $codes = [];
        foreach (self::MODULES as $module => $definition) {
            foreach (self::CRUD as $action) {
                $codes[] = "{$module}.{$action}";
            }
            foreach (array_keys($definition['actions'] ?? []) as $action) {
                $codes[] = "{$module}.{$action}";
            }
        }

        return $codes;
    }

    public static function existe(string $code): bool
    {
        [$module, $action] = array_pad(explode('.', $code, 2), 2, '');

        return isset(self::MODULES[$module]) && (in_array($action, self::CRUD, true) || isset(self::MODULES[$module]['actions'][$action]));
    }

    /** Libellé d'un module dans une langue. */
    public static function libelle(string $module, string $langue = 'fr'): string
    {
        return self::MODULES[$module][$langue] ?? self::MODULES[$module]['fr'] ?? $module;
    }

    /**
     * Catalogue pour les écrans (rôles, accès d'un compte) : onglets, modules, permissions avec leur libellé
     * et leur aide dans les deux langues.
     */
    public static function catalogue(): array
    {
        $onglets = [];
        foreach (self::ONGLETS as $cle => $libelles) {
            $onglets[$cle] = ['cle' => $cle] + $libelles + ['modules' => []];
        }
        foreach (self::MODULES as $module => $definition) {
            $permissions = [];
            foreach (self::crud($module) as $action) {
                $permissions[] = [
                    'code' => "{$module}.{$action}", 'action' => $action, 'base' => null,
                    'fr' => self::LIBELLES_CRUD[$action]['fr'], 'en' => self::LIBELLES_CRUD[$action]['en'],
                    'aide_fr' => self::aideCrud($action, $definition['fr'], 'fr'),
                    'aide_en' => self::aideCrud($action, $definition['en'], 'en'),
                ];
            }
            foreach ($definition['actions'] ?? [] as $action => $nommee) {
                $permissions[] = [
                    'code' => "{$module}.{$action}", 'action' => $action, 'base' => "{$module}.{$nommee['base']}",
                    'fr' => $nommee['fr'], 'en' => $nommee['en'], 'aide_fr' => $nommee['aide_fr'], 'aide_en' => $nommee['aide_en'],
                ];
            }
            $onglets[$definition['onglet']]['modules'][] = [
                'cle' => $module, 'fr' => $definition['fr'], 'en' => $definition['en'], 'permissions' => $permissions,
            ];
        }

        return array_values($onglets);
    }

    private static function aideCrud(string $action, string $module, string $langue): string
    {
        return match ($langue) {
            'en' => match ($action) {
                'voir' => "Open the \"{$module}\" screen and view its data.",
                'creer' => "Create new items in \"{$module}\".",
                'modifier' => "Change existing items in \"{$module}\".",
                default => "Delete items from \"{$module}\".",
            },
            default => match ($action) {
                'voir' => "Ouvrir l'écran « {$module} » et consulter ses données.",
                'creer' => "Créer de nouveaux éléments dans « {$module} ».",
                'modifier' => "Modifier les éléments existants de « {$module} ».",
                default => "Supprimer des éléments de « {$module} ».",
            },
        };
    }
}
