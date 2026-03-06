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
  PackageCheck
} from 'lucide-react';
import { useState } from 'react';
import { useAuth } from '@/contexts/AuthContext';
import { usePermissions } from '@/contexts/PermissionsContext';
import NotificationBell from './NotificationBell';
import LanguageSelector from './LanguageSelector';
import ThemeToggle from './ThemeToggle';
import { useLanguage } from '@/i18n/useLanguage';

const getNavItems = (t: ReturnType<typeof useLanguage>['t']) => [
  { path: '/', label: t.nav.dashboard, icon: LayoutDashboard, module: 'dashboard' },
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
  { path: '/inventaire-tournant', label: t.nav.rotatingInventory, icon: RotateCcw, module: 'inventaire_tournant' },
  { path: '/produits', label: t.nav.products, icon: Package, module: 'produits' },
  { path: '/secteurs', label: t.nav.sectors, icon: MapPin, module: 'secteurs' },
  { path: '/employes', label: t.nav.employees, icon: Users, module: 'employes' },
  { path: '/roles', label: 'Rôles', icon: Shield, module: 'roles' },
  { path: '/fournisseurs', label: 'Fournisseurs', icon: Truck, module: 'fournisseurs' },
  { path: '/commandes-fournisseur', label: 'Commandes', icon: ShoppingCart, module: 'commandes_fournisseur' },
  { path: '/receptions', label: 'Réceptions', icon: PackageCheck, module: 'receptions' },
];

export default function Layout() {
  const location = useLocation();
  const navigate = useNavigate();
  const { user, tenant, logout } = useAuth();
  const { t } = useLanguage();
  const { canView, loading: permissionsLoading } = usePermissions();
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const allNavItems = getNavItems(t);
  
  // Filtrer les modules selon les permissions
  const navItems = permissionsLoading 
    ? allNavItems 
    : allNavItems.filter(item => canView(item.module));

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
          {navItems.map((item) => {
            const Icon = item.icon;
            const isActive = location.pathname === item.path;
            return (
              <Link
                key={item.path}
                to={item.path}
                onClick={() => setSidebarOpen(false)}
                className={`
                  flex items-center gap-3 px-4 py-3 rounded-lg transition-colors
                  ${isActive 
                    ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 font-medium' 
                    : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'
                  }
                `}
              >
                <Icon size={20} />
                {item.label}
              </Link>
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
