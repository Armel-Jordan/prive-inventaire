import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { 
  LayoutDashboard, 
  Package,
  Paperclip,
  Users,
  MapPin, 
  ClipboardList,
  Menu,
  X,
  LogOut,
  Building2,
  ArrowRightLeft,
  Coins,
  Timer,
  BarChart3,
  Bell,
  ListChecks,
  GitCompare,
  History,
  CalendarClock,
  ShieldCheck,
  Route,
  Tag,
  FileBarChart,
  RotateCcw,
  User,
  Shield,
  ListPlus,
  Truck,
  ShoppingCart,
  PackageCheck,
  ChevronDown,
  ChevronRight,
  Warehouse,
  HandCoins,
  Settings,
  Library,
  Mail,
  Globe
} from 'lucide-react';
import { useState } from 'react';
import { ArchiveRestore, BadgePercent, Banknote, BellRing, Calculator, Gift, Lightbulb, ShoppingBasket, BookOpen, BookText, CalendarRange, FileClock, FileSignature, FileSpreadsheet, Hourglass, PackageMinus, PackageOpen, PackageSearch, ReceiptText, FileInput, Repeat, Scale, ScrollText, Wallet } from 'lucide-react';
import { useAuth } from '@/contexts/AuthContext';
import { usePermissions } from '@/contexts/PermissionsContext';
import NotificationBell from './NotificationBell';
import LanguageSelector from './LanguageSelector';
import ThemeToggle from './ThemeToggle';
import RechercheGlobale from './RechercheGlobale';
import { useLanguage } from '@/i18n/useLanguage';
import { ClipboardCheck } from 'lucide-react';
import { NOM_APPLICATION } from '@/config/application';

interface NavItem {
  path: string;
  label: string;
  icon: React.ComponentType<{ size?: number }>;
  module: string;
  /** Action nommée exigée en plus (ex. « etats » de la comptabilité) ; sinon le droit de consulter le module. */
  action?: string;
}

interface NavCategory {
  id: string;
  label: string;
  icon: React.ComponentType<{ size?: number }>;
  items: NavItem[];
}

const getNavCategories = (t: ReturnType<typeof useLanguage>['t']): NavCategory[] => [
  {
    id: 'inventaire',
    label: 'Inventaire',
    icon: ClipboardList,
    items: [
      { path: '/scans', label: t.nav.scans, icon: ClipboardList, module: 'inventaires' },
      { path: '/prises-inventaire', label: t.stocktake.nav, icon: ClipboardCheck, module: 'inventaires' },
      { path: '/valorisation-inventaire', label: t.valuation.nav, icon: Coins, module: 'valorisation' },
      { path: '/statistiques', label: t.nav.statistics, icon: BarChart3, module: 'statistiques' },
      { path: '/comparaison', label: t.nav.comparison, icon: GitCompare, module: 'comparaison' },
      { path: '/alertes', label: t.nav.alerts, icon: Bell, module: 'alertes' },
      { path: '/audit', label: t.nav.history, icon: History, module: 'historique' },
      { path: '/tracabilite', label: t.nav.traceability, icon: Route, module: 'tracabilite' },
      { path: '/relocalisation', label: t.nav.relocation, icon: ArrowRightLeft, module: 'relocalisation' },
      { path: '/planification', label: t.nav.planning, icon: CalendarClock, module: 'planification' },
      { path: '/approbations', label: t.nav.approvals, icon: ShieldCheck, module: 'approbations' },
      { path: '/rapports', label: t.nav.reports, icon: FileBarChart, module: 'rapports' },
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
      { path: '/grand-livre', label: t.accounting.ledgerNav, icon: ScrollText, module: 'comptabilite', action: 'etats' },
      { path: '/balance-comptable', label: t.accounting.trialBalanceNav, icon: Scale, module: 'comptabilite', action: 'etats' },
      { path: '/etats-financiers', label: t.accounting.statementsNav, icon: FileSpreadsheet, module: 'comptabilite', action: 'etats' },
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
      { path: '/secteurs', label: t.nav.sectors, icon: MapPin, module: 'secteurs' },
      { path: '/configuration-a-completer', label: t.setup.title, icon: ListChecks, module: 'configuration' },
      { path: '/employes', label: t.nav.employees, icon: Users, module: 'employes' },
      { path: '/taches-planifiees', label: t.scheduledTasks.title, icon: Timer, module: 'configuration' },
      // Paramètres (module toujours actif) : les alertes portent aussi sur le stock, les achats et les ventes.
      { path: '/alertes-config', label: t.alertsConfig.nav, icon: Bell, module: 'alertes_config' },
      { path: '/fiches-employes', label: 'Fiches Employés', icon: User, module: 'fiches_employes' },
      { path: '/balises', label: t.tags.title, icon: Tag, module: 'configuration' },
      { path: '/roles', label: t.permissions.nav, icon: Shield, module: 'roles' },
      { path: '/champs-personnalises', label: t.customFields.title, icon: ListPlus, module: 'configuration' },
      { path: '/configuration', label: 'Configuration', icon: Settings, module: 'configuration' },
      { path: '/referentiels', label: t.nav.referentials, icon: Library, module: 'referentiels' },
      { path: '/frais', label: t.fees.nav, icon: BadgePercent, module: 'configuration' },
      { path: '/courriels', label: t.nav.sentEmails, icon: Mail, module: 'configuration' },
    ],
  },
];

export default function Layout() {
  const location = useLocation();
  const navigate = useNavigate();
  const { user, tenant, logout } = useAuth();
  const { t } = useLanguage();
  const { canView, peut, loading: permissionsLoading, permissions } = usePermissions();
  // Entrée du menu permise : droit de consulter le module, et l'action nommée exigée s'il y en a une.
  const permise = (item: NavItem) => canView(item.module) && (!item.action || peut(item.module, item.action));
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [expandedCategories, setExpandedCategories] = useState<string[]>(['inventaire']);
  const allCategories = getNavCategories(t);

  const toggleCategory = (categoryId: string) => {
    setExpandedCategories(prev =>
      prev.includes(categoryId)
        ? prev.filter(id => id !== categoryId)
        : [...prev, categoryId]
    );
  };

  // Modules fonctionnels souscrits par l'entreprise (abonnement).
  // Si absent (ancienne session), on n'applique pas le gating module (rétro-compat).
  const tenantModules = tenant?.modules;
  const moduleAllowed = (categoryId: string) =>
    !Array.isArray(tenantModules) ? true : tenantModules.includes(categoryId);

  // Filtrage à 2 niveaux : (1) module d'entreprise (abonnement), (2) permissions par rôle.
  // Pendant le chargement des permissions, ne filtre que par module.
  const categories = allCategories
    .filter(cat => moduleAllowed(cat.id))
    .map(cat => ({
      ...cat,
      items: permissionsLoading
        ? cat.items
        : cat.items.filter(item => {
            try {
              return permise(item);
            } catch {
              return true; // Si erreur, afficher par défaut
            }
          })
    }))
    .filter(cat => cat.items.length > 0);

  // Droits introuvables (échec de /permissions/me) : on garde au moins le gating module. Des droits chargés
  // qui ne donnent accès à aucune catégorie ne réaffichent plus tout le menu.
  const displayCategories = permissionsLoading || permissions
    ? categories
    : allCategories.filter(cat => moduleAllowed(cat.id));

  // Page du menu ouverte par son adresse sans le droit de la consulter : « Accès refusé » au lieu de la page
  // (l'API refuse de toute façon les écritures que le rôle ne permet pas).
  const pageCourante = allCategories.flatMap(cat => cat.items)
    .find(item => location.pathname === item.path || location.pathname.startsWith(`${item.path}/`));
  const accesRefuse = !permissionsLoading && permissions !== null && pageCourante !== undefined && !permise(pageCourante);
  // Page d'un module que l'entreprise n'a pas souscrit (Achats, Ventes, Finance) : message au lieu d'une page
  // vide, voire d'une erreur (les appels de l'API sont refusés).
  const categorieCourante = allCategories.find(cat => cat.items.some(item => item === pageCourante));
  const moduleInactif = categorieCourante !== undefined && !moduleAllowed(categorieCourante.id);

  const handleLogout = () => {
    logout();
    navigate('/login');
  };

  return (
    <div className="min-h-screen bg-gray-50 dark:bg-gray-900">
      {/* Mobile menu button */}
      <div className="lg:hidden fixed top-4 left-4 z-50">
        <button
          onClick={() => setSidebarOpen(!sidebarOpen)}
          className="p-2 bg-white rounded-lg shadow-md"
        >
          {sidebarOpen ? <X size={24} /> : <Menu size={24} />}
        </button>
      </div>

      {/* Sidebar */}
      <aside className={`
        fixed inset-y-0 left-0 z-40 w-64 bg-white dark:bg-gray-800 shadow-lg transform transition-transform duration-200 flex flex-col
        lg:translate-x-0 ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'}
      `}>
        <div className="p-6 border-b dark:border-gray-700">
          <div className="flex items-center gap-3 mb-2">
            <Building2 className="text-blue-600" size={24} />
            <h1 className="text-xl font-bold text-gray-800 dark:text-white">{NOM_APPLICATION}</h1>
          </div>
          {tenant && (
            <p className="text-sm text-blue-600 font-medium">{tenant.nom}</p>
          )}
        </div>
        
        <nav className="p-4 space-y-1 flex-1 overflow-y-auto">
          {/* Dashboard - always visible */}
          <Link
            to="/"
            onClick={() => setSidebarOpen(false)}
            className={`
              flex items-center gap-3 px-4 py-2.5 rounded-lg transition-colors
              ${location.pathname === '/'
                ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 font-medium'
                : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'
              }
            `}
          >
            <LayoutDashboard size={20} />
            {t.nav.dashboard}
          </Link>

          {/* Categories */}
          {displayCategories.map((category) => {
            const CategoryIcon = category.icon;
            const isExpanded = expandedCategories.includes(category.id);
            const hasActiveItem = category.items.some(item => location.pathname === item.path);

            return (
              <div key={category.id}>
                <button
                  onClick={() => toggleCategory(category.id)}
                  className={`
                    w-full flex items-center justify-between px-4 py-2.5 rounded-lg transition-colors
                    ${hasActiveItem
                      ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400'
                      : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'
                    }
                  `}
                >
                  <div className="flex items-center gap-3">
                    <CategoryIcon size={20} />
                    <span className="font-medium">{category.label}</span>
                  </div>
                  {isExpanded ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
                </button>

                {isExpanded && (
                  <div className="ml-4 mt-1 space-y-0.5 border-l-2 border-gray-200 dark:border-gray-700">
                    {category.items.map((item) => {
                      const Icon = item.icon;
                      const isActive = location.pathname === item.path;
                      return (
                        <Link
                          key={item.path}
                          to={item.path}
                          onClick={() => setSidebarOpen(false)}
                          className={`
                            flex items-center gap-3 px-4 py-2 ml-2 rounded-lg transition-colors text-sm
                            ${isActive
                              ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 font-medium'
                              : 'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700'
                            }
                          `}
                        >
                          <Icon size={16} />
                          {item.label}
                        </Link>
                      );
                    })}
                  </div>
                )}
              </div>
            );
          })}
        </nav>

        {/* User info & logout */}
        <div className="p-4 border-t dark:border-gray-700">
          {user && (
            <div className="mb-3">
              <p className="text-sm font-medium text-gray-800 dark:text-white">{user.nom}</p>
              <p className="text-xs text-gray-500 dark:text-gray-400">{user.email}</p>
            </div>
          )}
          <button
            onClick={handleLogout}
            className="w-full flex items-center gap-2 px-4 py-2 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 rounded-lg transition-colors"
          >
            <LogOut size={18} />
            {t.nav.logout}
          </button>
        </div>
      </aside>

      {/* Overlay for mobile */}
      {sidebarOpen && (
        <div 
          className="fixed inset-0 bg-black/50 z-30 lg:hidden"
          onClick={() => setSidebarOpen(false)}
        />
      )}

      {/* Main content */}
      <main className="lg:ml-64 min-h-screen bg-gray-100 dark:bg-gray-900">
        {/* Top bar with notifications */}
        {/* Sur téléphone, la place du bouton du menu (fixe, en haut à gauche) est laissée libre. */}
        <div className="sticky top-0 z-20 bg-white dark:bg-gray-800 border-b dark:border-gray-700 pl-16 pr-3 sm:pr-6 lg:px-6 py-3 flex items-center justify-end gap-1 sm:gap-4">
          <RechercheGlobale />
          <ThemeToggle />
          <LanguageSelector />
          <NotificationBell />
          <Link
            to="/securite"
            className="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full transition-colors"
            title={t.security.link}
            aria-label={t.security.link}
          >
            <Shield size={20} className="text-gray-600 dark:text-gray-300" />
          </Link>
          <Link
            to="/profil"
            className="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full transition-colors"
            title="Mon profil"
          >
            <User size={20} className="text-gray-600 dark:text-gray-300" />
          </Link>
        </div>
        <div className="p-6 lg:p-8">
          {moduleInactif ? (
            <div className="flex flex-col items-center justify-center text-center min-h-[60vh]">
              <ShieldCheck size={48} className="text-gray-300 dark:text-gray-600 mb-4" aria-hidden />
              <h1 className="text-2xl font-bold text-gray-800 dark:text-white mb-2">{t.moduleDisabled.title}</h1>
              <p className="text-gray-500 dark:text-gray-400 mb-6 max-w-lg">
                {t.moduleDisabled.message.replace('{module}', categorieCourante?.label ?? '')}
              </p>
              <Link to="/" className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">{t.moduleDisabled.back}</Link>
            </div>
          ) : accesRefuse ? (
            <div className="flex flex-col items-center justify-center text-center min-h-[60vh]">
              <ShieldCheck size={48} className="text-gray-300 dark:text-gray-600 mb-4" aria-hidden />
              <h1 className="text-2xl font-bold text-gray-800 dark:text-white mb-2">{t.accessDenied.title}</h1>
              <p className="text-gray-500 dark:text-gray-400 mb-6 max-w-lg">{t.accessDenied.message}</p>
              <Link to="/" className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">{t.accessDenied.back}</Link>
            </div>
          ) : (
            <Outlet />
          )}
        </div>
      </main>
    </div>
  );
}
