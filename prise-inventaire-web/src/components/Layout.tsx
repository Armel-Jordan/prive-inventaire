import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { 
  LayoutDashboard, 
  Package, 
  Users, 
  MapPin, 
  ClipboardList,
  Menu,
  X,
  LogOut,
  Building2,
  ArrowRightLeft,
  BarChart3,
  Bell,
  GitCompare,
  History,
  CalendarClock,
  ShieldCheck,
  Route,
  FileBarChart,
  RotateCcw,
  User,
  Shield,
  Truck,
  ShoppingCart,
  PackageCheck,
  ChevronDown,
  ChevronRight,
  Warehouse,
  HandCoins,
  Settings
} from 'lucide-react';
import { useState } from 'react';
import { useAuth } from '@/contexts/AuthContext';
import { usePermissions } from '@/contexts/PermissionsContext';
import NotificationBell from './NotificationBell';
import LanguageSelector from './LanguageSelector';
import ThemeToggle from './ThemeToggle';
import { useLanguage } from '@/i18n/useLanguage';

interface NavItem {
  path: string;
  label: string;
  icon: React.ComponentType<{ size?: number }>;
  module: string;
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
      { path: '/commandes-fournisseur', label: 'Commandes', icon: ShoppingCart, module: 'commandes_fournisseur' },
      { path: '/receptions', label: 'Réceptions', icon: PackageCheck, module: 'receptions' },
    ],
  },
  {
    id: 'ventes',
    label: 'Ventes',
    icon: HandCoins,
    items: [
      { path: '/clients', label: 'Clients', icon: Users, module: 'clients' },
      { path: '/devis', label: 'Devis', icon: FileBarChart, module: 'devis' },
      { path: '/commandes-client', label: 'Commandes', icon: ShoppingCart, module: 'commandes_client' },
      { path: '/factures', label: 'Factures', icon: FileBarChart, module: 'factures' },
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
      { path: '/previsions-stock', label: 'Prévisions', icon: BarChart3, module: 'previsions_stock' },
    ],
  },
  {
    id: 'parametres',
    label: 'Paramètres',
    icon: Settings,
    items: [
      { path: '/produits', label: t.nav.products, icon: Package, module: 'produits' },
      { path: '/secteurs', label: t.nav.sectors, icon: MapPin, module: 'secteurs' },
      { path: '/employes', label: t.nav.employees, icon: Users, module: 'employes' },
      { path: '/roles', label: 'Rôles', icon: Shield, module: 'roles' },
    ],
  },
];

export default function Layout() {
  const location = useLocation();
  const navigate = useNavigate();
  const { user, tenant, logout } = useAuth();
  const { t } = useLanguage();
  const { canView, loading: permissionsLoading } = usePermissions();
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

  // Filtrer les catégories selon les permissions
  // Pendant le chargement, afficher tout. Sinon filtrer selon canView
  const categories = allCategories.map(cat => ({
    ...cat,
    items: permissionsLoading 
      ? cat.items 
      : cat.items.filter(item => {
          try {
            return canView(item.module);
          } catch {
            return true; // Si erreur, afficher par défaut
          }
        })
  })).filter(cat => cat.items.length > 0);
  
  // Si aucune catégorie après filtrage, afficher tout (fallback)
  const displayCategories = categories.length > 0 ? categories : allCategories;

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
            <h1 className="text-xl font-bold text-gray-800 dark:text-white">Prise Inventaire</h1>
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
      <main className="lg:ml-64 min-h-screen">
        {/* Top bar with notifications */}
        <div className="sticky top-0 z-20 bg-white dark:bg-gray-800 border-b dark:border-gray-700 px-6 py-3 flex items-center justify-end gap-4">
          <ThemeToggle />
          <LanguageSelector />
          <NotificationBell />
          <Link
            to="/profil"
            className="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full transition-colors"
            title="Mon profil"
          >
            <User size={20} className="text-gray-600 dark:text-gray-300" />
          </Link>
        </div>
        <div className="p-6 lg:p-8">
          <Outlet />
        </div>
      </main>
    </div>
  );
}
