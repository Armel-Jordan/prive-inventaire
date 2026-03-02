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
  RotateCcw
} from 'lucide-react';
import { useState } from 'react';
import { useAuth } from '@/contexts/AuthContext';
import NotificationBell from './NotificationBell';
import LanguageSelector from './LanguageSelector';
import { useLanguage } from '@/i18n/useLanguage';

const getNavItems = (t: ReturnType<typeof useLanguage>['t']) => [
  { path: '/', label: t.nav.dashboard, icon: LayoutDashboard },
  { path: '/scans', label: t.nav.scans, icon: ClipboardList },
  { path: '/statistiques', label: t.nav.statistics, icon: BarChart3 },
  { path: '/comparaison', label: t.nav.comparison, icon: GitCompare },
  { path: '/alertes', label: t.nav.alerts, icon: Bell },
  { path: '/audit', label: t.nav.history, icon: History },
  { path: '/tracabilite', label: t.nav.traceability, icon: Route },
  { path: '/relocalisation', label: t.nav.relocation, icon: ArrowRightLeft },
  { path: '/planification', label: t.nav.planning, icon: CalendarClock },
  { path: '/approbations', label: t.nav.approvals, icon: ShieldCheck },
  { path: '/rapports', label: t.nav.reports, icon: FileBarChart },
  { path: '/inventaire-tournant', label: t.nav.rotatingInventory, icon: RotateCcw },
  { path: '/produits', label: t.nav.products, icon: Package },
  { path: '/secteurs', label: t.nav.sectors, icon: MapPin },
  { path: '/employes', label: t.nav.employees, icon: Users },
];

export default function Layout() {
  const location = useLocation();
  const navigate = useNavigate();
  const { user, tenant, logout } = useAuth();
  const { t } = useLanguage();
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const navItems = getNavItems(t);

  const handleLogout = () => {
    logout();
    navigate('/login');
  };

  return (
    <div className="min-h-screen bg-gray-50">
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
        fixed inset-y-0 left-0 z-40 w-64 bg-white shadow-lg transform transition-transform duration-200 flex flex-col
        lg:translate-x-0 ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'}
      `}>
        <div className="p-6 border-b">
          <div className="flex items-center gap-3 mb-2">
            <Building2 className="text-blue-600" size={24} />
            <h1 className="text-xl font-bold text-gray-800">Prise Inventaire</h1>
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
                    ? 'bg-blue-50 text-blue-700 font-medium' 
                    : 'text-gray-600 hover:bg-gray-100'
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
        <div className="p-4 border-t">
          {user && (
            <div className="mb-3">
              <p className="text-sm font-medium text-gray-800">{user.nom}</p>
              <p className="text-xs text-gray-500">{user.email}</p>
            </div>
          )}
          <button
            onClick={handleLogout}
            className="w-full flex items-center gap-2 px-4 py-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors"
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
        <div className="sticky top-0 z-20 bg-white border-b px-6 py-3 flex items-center justify-end gap-4">
          <LanguageSelector />
          <NotificationBell />
        </div>
        <div className="p-6 lg:p-8">
          <Outlet />
        </div>
      </main>
    </div>
  );
}
