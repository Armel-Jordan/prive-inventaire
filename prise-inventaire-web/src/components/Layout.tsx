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
  Route
} from 'lucide-react';
import { useState } from 'react';
import { useAuth } from '@/contexts/AuthContext';

const navItems = [
  { path: '/', label: 'Tableau de bord', icon: LayoutDashboard },
  { path: '/scans', label: 'Inventaires', icon: ClipboardList },
  { path: '/statistiques', label: 'Statistiques', icon: BarChart3 },
  { path: '/comparaison', label: 'Comparaison', icon: GitCompare },
  { path: '/alertes', label: 'Alertes Stock', icon: Bell },
  { path: '/audit', label: 'Historique', icon: History },
  { path: '/tracabilite', label: 'Traçabilité', icon: Route },
  { path: '/relocalisation', label: 'Relocalisation', icon: ArrowRightLeft },
  { path: '/planification', label: 'Planification', icon: CalendarClock },
  { path: '/approbations', label: 'Approbations', icon: ShieldCheck },
  { path: '/produits', label: 'Produits', icon: Package },
  { path: '/secteurs', label: 'Secteurs', icon: MapPin },
  { path: '/employes', label: 'Employés', icon: Users },
];

export default function Layout() {
  const location = useLocation();
  const navigate = useNavigate();
  const { user, tenant, logout } = useAuth();
  const [sidebarOpen, setSidebarOpen] = useState(false);

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
        
        <nav className="p-4 space-y-1 flex-1">
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
            Déconnexion
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
        <div className="p-6 lg:p-8">
          <Outlet />
        </div>
      </main>
    </div>
  );
}
