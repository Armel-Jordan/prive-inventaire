import type { ComponentType } from 'react';
import {
  ArchiveRestore, ArrowRightLeft, BadgePercent, Banknote, BanknoteArrowUp, BarChart3, Bell, BellRing, BookOpen, BookText,
  Calculator, CalendarClock, CalendarRange, ClipboardCheck, ClipboardList, Coins, FileBarChart, FileClock, FileInput,
  FileSignature, FileSpreadsheet, Gift, GitCompare, Globe, HandCoins, History, Hourglass, Landmark, Layers, Library,
  LibraryBig, Lightbulb, ListChecks, ListPlus, Lock, Mail, MapPin, Package, PackageCheck, PackageMinus, PackageOpen,
  PackageSearch, Paperclip, PiggyBank, ReceiptText, Repeat, RotateCcw, Route, Scale, ScrollText, Send, Settings, Shield,
  Palette, ShieldCheck, ShoppingBasket, ShoppingCart, Tag, Tags, Timer, Truck, User, Users, Wallet, Warehouse,
} from 'lucide-react';
import type { TranslationKeys } from '@/i18n/translations';

/**
 * Menu du back-office : catégories et écrans, avec le module (et l'action nommée) qui en ouvre l'accès. Partagé par le
 * menu latéral, la carte des écrans de l'aide et la recherche des écrans.
 */
export interface NavItem {
  path: string;
  label: string;
  icon: ComponentType<{ size?: number; className?: string }>;
  module: string;
  /** Action nommée exigée en plus (ex. « etats » de la comptabilité) ; sinon le droit de consulter le module. */
  action?: string;
}

export interface NavCategory {
  id: string;
  label: string;
  icon: ComponentType<{ size?: number; className?: string }>;
  items: NavItem[];
}

export const getNavCategories = (t: TranslationKeys): NavCategory[] => [
  {
    id: 'inventaire',
    label: 'Inventaire',
    icon: ClipboardList,
    items: [
      { path: '/scans', label: t.nav.scans, icon: ClipboardList, module: 'inventaires' },
      { path: '/prises-inventaire', label: t.stocktake.nav, icon: ClipboardCheck, module: 'inventaires' },
      { path: '/valorisation-inventaire', label: t.valuation.nav, icon: Coins, module: 'valorisation' },
      { path: '/stock-entrepots', label: t.warehouses.stockNav, icon: Warehouse, module: 'inventaires' },
      { path: '/lots', label: t.lots.nav, icon: Layers, module: 'lots' },
      { path: '/statistiques', label: t.nav.statistics, icon: BarChart3, module: 'statistiques' },
      { path: '/comparaison', label: t.nav.comparison, icon: GitCompare, module: 'comparaison' },
      { path: '/alertes', label: t.nav.alerts, icon: Bell, module: 'alertes' },
      { path: '/audit', label: t.nav.history, icon: History, module: 'historique' },
      { path: '/tracabilite', label: t.nav.traceability, icon: Route, module: 'tracabilite' },
      { path: '/relocalisation', label: t.nav.relocation, icon: ArrowRightLeft, module: 'relocalisation' },
      { path: '/planification', label: t.nav.planning, icon: CalendarClock, module: 'planification' },
      { path: '/approbations', label: t.nav.approvals, icon: ShieldCheck, module: 'approbations' },
      { path: '/rapports', label: t.nav.reports, icon: FileBarChart, module: 'rapports' },
      { path: '/bibliotheque-rapports', label: t.reportsLibrary.nav, icon: LibraryBig, module: 'rapports' },
      { path: '/inventaire-tournant', label: 'Inv. Tournant', icon: RotateCcw, module: 'inventaire_tournant' },
    ],
  },
  {
    id: 'achats',
    label: 'Achats',
    icon: Warehouse,
    items: [
      { path: '/fournisseurs', label: 'Fournisseurs', icon: Truck, module: 'fournisseurs' },
      { path: '/suggestions-achat', label: t.purchaseSuggestions.nav, icon: Lightbulb, module: 'suggestions_achat' },
      { path: '/commandes-fournisseur', label: 'Commandes', icon: ShoppingCart, module: 'commandes_fournisseur' },
      { path: '/receptions', label: 'Réceptions', icon: PackageCheck, module: 'receptions' },
      { path: '/retours-fournisseurs', label: t.supplierReturns.nav, icon: PackageMinus, module: 'retours_fournisseurs' },
      { path: '/factures-fournisseurs', label: t.supplierInvoices.nav, icon: FileInput, module: 'factures_fournisseurs' },
      { path: '/debourses', label: t.supplierPayments.nav, icon: BanknoteArrowUp, module: 'debourses' },
      { path: '/paiements-dus', label: t.paymentsDue.nav, icon: CalendarClock, module: 'debourses' },
      { path: '/virements', label: t.transfers.nav, icon: Send, module: 'debourses' },
    ],
  },
  {
    id: 'ventes',
    label: 'Ventes',
    icon: HandCoins,
    items: [
      { path: '/clients', label: 'Clients', icon: Users, module: 'clients' },
      { path: '/espace-client', label: t.portalAdmin.nav, icon: Globe, module: 'espace_client' },
      { path: '/devis', label: 'Devis', icon: FileBarChart, module: 'devis' },
      { path: '/commandes-client', label: 'Commandes', icon: ShoppingCart, module: 'commandes_client' },
      { path: '/reliquats-clients', label: t.backOrders.nav, icon: Hourglass, module: 'reliquats_clients' },
      { path: '/disponibilites', label: t.availability.nav, icon: PackageSearch, module: 'commandes_client' },
      { path: '/factures', label: 'Factures', icon: FileBarChart, module: 'factures' },
      { path: '/abonnements-clients', label: t.subscriptions.nav, icon: Repeat, module: 'abonnements_clients' },
      { path: '/contrats-location', label: t.rentals.nav, icon: FileSignature, module: 'contrats_location' },
      { path: '/consignations', label: t.consignment.nav, icon: PackageOpen, module: 'consignations' },
      { path: '/notes-credit', label: t.creditNotes.nav, icon: ReceiptText, module: 'avoirs' },
      { path: '/retours-clients', label: t.customerReturns.nav, icon: ArchiveRestore, module: 'retours_clients' },
      { path: '/encaissements', label: t.customerPayments.nav, icon: Coins, module: 'encaissements' },
      { path: '/acomptes', label: t.deposits.nav, icon: Wallet, module: 'acomptes' },
      { path: '/demandes-acompte', label: t.depositRequests.nav, icon: FileClock, module: 'demandes_acompte' },
      { path: '/point-de-vente', label: t.pointOfSale.nav, icon: ShoppingBasket, module: 'point_de_vente' },
      { path: '/caisses', label: t.cashRegisters.nav, icon: Banknote, module: 'caisses' },
      { path: '/cartes-cadeaux', label: t.giftCards.nav, icon: Gift, module: 'cartes_cadeaux' },
      { path: '/comptes-clients', label: t.receivables.nav, icon: HandCoins, module: 'comptes_clients' },
      { path: '/relances', label: t.reminders.nav, icon: BellRing, module: 'relances' },
      { path: '/bons-livraison', label: 'Bons Livraison', icon: PackageCheck, module: 'bons_livraison' },
      { path: '/camions', label: 'Camions', icon: Truck, module: 'camions' },
      { path: '/tournees', label: 'Tournées', icon: Route, module: 'tournees' },
    ],
  },
  {
    id: 'finance',
    label: 'Finance',
    icon: BarChart3,
    items: [
      { path: '/comptabilite', label: 'Comptabilité', icon: FileBarChart, module: 'comptabilite' },
      { path: '/plan-comptable', label: t.accounting.chartNav, icon: BookOpen, module: 'comptabilite' },
      { path: '/exercices-comptables', label: t.accounting.yearsNav, icon: CalendarRange, module: 'comptabilite' },
      { path: '/journal-comptable', label: t.accounting.journalNav, icon: BookText, module: 'comptabilite' },
      { path: '/ecritures-recurrentes', label: t.accounting.recurringNav, icon: Repeat, module: 'comptabilite' },
      { path: '/comptabilisation', label: t.accounting.autoPostingNav, icon: Calculator, module: 'comptabilite' },
      { path: '/banque', label: t.bank.nav, icon: Landmark, module: 'banque' },
      { path: '/budgets', label: t.budgets.nav, icon: PiggyBank, module: 'budgets' },
      { path: '/grand-livre', label: t.accounting.ledgerNav, icon: ScrollText, module: 'comptabilite', action: 'etats' },
      { path: '/balance-comptable', label: t.accounting.trialBalanceNav, icon: Scale, module: 'comptabilite', action: 'etats' },
      { path: '/etats-financiers', label: t.accounting.statementsNav, icon: FileSpreadsheet, module: 'comptabilite', action: 'etats' },
      { path: '/rapport-taxes', label: t.taxReport.nav, icon: BadgePercent, module: 'comptabilite', action: 'etats' },
      { path: '/previsions-stock', label: 'Prévisions', icon: BarChart3, module: 'previsions_stock' },
      { path: '/gestion-prix', label: 'Tarifs & Prix', icon: FileBarChart, module: 'gestion_prix' },
    ],
  },
  {
    id: 'parametres',
    label: 'Paramètres',
    icon: Settings,
    items: [
      { path: '/pieces-jointes', label: t.attachments.title, icon: Paperclip, module: 'configuration' },
      { path: '/produits', label: t.nav.products, icon: Package, module: 'produits' },
      { path: '/etiquettes', label: t.labels.nav, icon: Tags, module: 'etiquettes' },
      { path: '/secteurs', label: t.nav.sectors, icon: MapPin, module: 'secteurs' },
      { path: '/configuration-a-completer', label: t.setup.title, icon: ListChecks, module: 'configuration' },
      { path: '/employes', label: t.nav.employees, icon: Users, module: 'employes' },
      { path: '/taches-planifiees', label: t.scheduledTasks.title, icon: Timer, module: 'taches_planifiees' },
      // Paramètres (module toujours actif) : les alertes portent aussi sur le stock, les achats et les ventes.
      { path: '/alertes-config', label: t.alertsConfig.nav, icon: Bell, module: 'alertes_config' },
      { path: '/fiches-employes', label: 'Fiches Employés', icon: User, module: 'fiches_employes' },
      { path: '/balises', label: t.tags.title, icon: Tag, module: 'configuration' },
      { path: '/roles', label: t.permissions.nav, icon: Shield, module: 'roles' },
      { path: '/fiches-en-cours', label: t.locks.nav, icon: Lock, module: 'verrous' },
      { path: '/champs-personnalises', label: t.customFields.title, icon: ListPlus, module: 'configuration' },
      { path: '/configuration', label: 'Configuration', icon: Settings, module: 'configuration' },
      { path: '/referentiels', label: t.nav.referentials, icon: Library, module: 'referentiels' },
      // Libellés et couleurs des statuts des documents (« Statuts transactions » de fripv2).
      { path: '/statuts-documents', label: t.documentStatuses.title, icon: Palette, module: 'configuration' },
      { path: '/frais', label: t.fees.nav, icon: BadgePercent, module: 'configuration' },
      { path: '/courriels', label: t.nav.sentEmails, icon: Mail, module: 'configuration' },
    ],
  },
];

/** Écran du menu d'une adresse (« /clients/12 » relève de « /clients »). */
export function ecranDuMenu(categories: NavCategory[], chemin: string): NavItem | undefined {
  return categories.flatMap(cat => cat.items)
    .find(item => chemin === item.path || chemin.startsWith(`${item.path}/`));
}

/** L'adresse suit-elle le modèle (« /clients/12 » suit « /clients/:id ») ? */
export function suitLeModele(modele: string, chemin: string): boolean {
  const attendus = modele.split('/');
  const segments = chemin.split('/');
  return attendus.length === segments.length && attendus.every((segment, i) => segment.startsWith(':') || segment === segments[i]);
}

/** Titre d'un écran : celui des écrans hors menu (tableau de bord, fiches…), sinon son libellé dans le menu. */
export function titreEcran(t: TranslationKeys, chemin: string): string {
  const titres = t.help.titles;
  const modele = titres[chemin] !== undefined ? chemin : Object.keys(titres).find(cle => cle.includes(':') && suitLeModele(cle, chemin));
  if (modele !== undefined) return titres[modele];
  return ecranDuMenu(getNavCategories(t), chemin)?.label ?? t.help.title;
}
