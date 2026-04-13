import { useEffect, useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import {
  Building2, Users, AlertTriangle, CheckCircle, Plus, LogOut, Shield,
  Search, RefreshCw, Clock, TrendingUp, ChevronRight, ToggleLeft, ToggleRight,
} from 'lucide-react';

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const STORAGE_KEY = 'prise_super_admin';

interface Stats {
  total_tenants: number;
  tenants_actifs: number;
  total_admins: number;
  tenants_expires: number;
}

interface Tenant {
  id: number;
  nom: string;
  slug: string;
  plan: string;
  actif: boolean;
  date_expiration: string;
  admin_users_count: number;
  renouvelable: boolean;
  duree_abonnement: number;
}

type FilterTab = 'tous' | 'actifs' | 'alertes' | 'expires';

function getDaysLeft(dateStr: string): number {
  return Math.ceil((new Date(dateStr).getTime() - Date.now()) / 86400000);
}

function ExpirationBadge({ dateStr }: { dateStr: string }) {
  const days = getDaysLeft(dateStr);
  if (days < 0)
    return <span className="px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">Expiré</span>;
  if (days <= 30)
    return <span className="px-2 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-700">{days}j restants</span>;
  return <span className="text-sm text-gray-500">{new Date(dateStr).toLocaleDateString('fr-FR')}</span>;
}

function PlanBadge({ plan }: { plan: string }) {
  const styles: Record<string, string> = {
    enterprise: 'bg-purple-100 text-purple-700 border border-purple-200',
    pro: 'bg-blue-100 text-blue-700 border border-blue-200',
    starter: 'bg-gray-100 text-gray-600 border border-gray-200',
  };
  const labels: Record<string, string> = {
    enterprise: 'Enterprise',
    pro: 'Pro',
    starter: 'Starter',
  };
  return (
    <span className={`px-2 py-0.5 rounded text-xs font-medium ${styles[plan] ?? styles.starter}`}>
      {labels[plan] ?? plan}
    </span>
  );
}

function getAuth() {
  const stored = localStorage.getItem(STORAGE_KEY);
  if (!stored) return null;
  try { return JSON.parse(stored); } catch { return null; }
}

export default function SuperAdminDashboard() {
  const navigate = useNavigate();
  const [stats, setStats] = useState<Stats | null>(null);
  const [tenants, setTenants] = useState<Tenant[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [filterTab, setFilterTab] = useState<FilterTab>('tous');
  const [togglingId, setTogglingId] = useState<number | null>(null);
  const auth = getAuth();

  useEffect(() => {
    if (!auth) { navigate('/super-admin'); return; }
    loadData();
  }, []);

  async function fetchApi(endpoint: string, options?: RequestInit) {
    const res = await fetch(`${API_BASE_URL}${endpoint}`, {
      ...options,
      headers: {
        'Authorization': `Bearer ${auth?.token}`,
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        ...options?.headers,
      },
    });
    if (!res.ok) throw new Error('Erreur API');
    return res.json();
  }

  async function loadData() {
    setLoading(true);
    try {
      const [statsData, tenantsData] = await Promise.all([
        fetchApi('/super-admin/stats'),
        fetchApi('/super-admin/tenants'),
      ]);
      setStats(statsData);
      setTenants(tenantsData);
    } catch { /* ignore */ }
    finally { setLoading(false); }
  }

  async function toggleActif(tenant: Tenant) {
    setTogglingId(tenant.id);
    try {
      await fetchApi(`/super-admin/tenants/${tenant.id}`, {
        method: 'PUT',
        body: JSON.stringify({ actif: !tenant.actif }),
      });
      setTenants(prev => prev.map(t => t.id === tenant.id ? { ...t, actif: !t.actif } : t));
      setStats(prev => prev ? {
        ...prev,
        tenants_actifs: prev.tenants_actifs + (tenant.actif ? -1 : 1),
      } : prev);
    } catch { /* ignore */ }
    finally { setTogglingId(null); }
  }

  const filtered = tenants.filter(t => {
    const q = search.toLowerCase();
    if (q && !t.nom.toLowerCase().includes(q) && !t.slug.toLowerCase().includes(q)) return false;
    const days = getDaysLeft(t.date_expiration);
    if (filterTab === 'actifs') return t.actif && days >= 0;
    if (filterTab === 'alertes') return days >= 0 && days <= 30;
    if (filterTab === 'expires') return days < 0;
    return true;
  });

  const tabCounts = {
    tous: tenants.length,
    actifs: tenants.filter(t => t.actif && getDaysLeft(t.date_expiration) >= 0).length,
    alertes: tenants.filter(t => { const d = getDaysLeft(t.date_expiration); return d >= 0 && d <= 30; }).length,
    expires: tenants.filter(t => getDaysLeft(t.date_expiration) < 0).length,
  };

  return (
    <div className="min-h-screen bg-slate-950">
      {/* Header */}
      <header className="bg-slate-900 border-b border-slate-800 sticky top-0 z-10">
        <div className="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="p-2 bg-purple-600 rounded-lg">
              <Shield size={20} className="text-white" />
            </div>
            <div>
              <span className="text-white font-semibold text-sm">Prise Inventaire</span>
              <span className="ml-2 px-2 py-0.5 bg-purple-900 text-purple-300 text-xs rounded-full font-medium">
                Super Admin
              </span>
            </div>
          </div>
          <div className="flex items-center gap-3">
            <button
              onClick={loadData}
              className="p-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition-colors"
              title="Actualiser"
            >
              <RefreshCw size={18} />
            </button>
            <div className="h-5 w-px bg-slate-700" />
            <span className="text-slate-300 text-sm">{auth?.user?.nom}</span>
            <button
              onClick={() => { localStorage.removeItem(STORAGE_KEY); navigate('/super-admin'); }}
              className="flex items-center gap-1.5 px-3 py-1.5 text-sm text-red-400 hover:bg-red-950 rounded-lg transition-colors"
            >
              <LogOut size={16} />
              Déconnexion
            </button>
          </div>
        </div>
      </header>

      <main className="max-w-7xl mx-auto px-6 py-8">
        {/* Stats */}
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
          {[
            { label: 'Entreprises', value: stats?.total_tenants ?? 0, icon: Building2, color: 'text-blue-400', bg: 'bg-blue-950' },
            { label: 'Actives', value: stats?.tenants_actifs ?? 0, icon: CheckCircle, color: 'text-emerald-400', bg: 'bg-emerald-950' },
            { label: 'Utilisateurs', value: stats?.total_admins ?? 0, icon: Users, color: 'text-purple-400', bg: 'bg-purple-950' },
            { label: 'Expirées', value: stats?.tenants_expires ?? 0, icon: AlertTriangle, color: 'text-red-400', bg: 'bg-red-950' },
          ].map(({ label, value, icon: Icon, color, bg }) => (
            <div key={label} className="bg-slate-900 border border-slate-800 rounded-xl p-5">
              <div className="flex items-center justify-between mb-3">
                <span className="text-slate-400 text-sm">{label}</span>
                <div className={`p-2 ${bg} rounded-lg`}>
                  <Icon size={16} className={color} />
                </div>
              </div>
              <p className="text-3xl font-bold text-white">{loading ? '—' : value}</p>
            </div>
          ))}
        </div>

        {/* Tenant List */}
        <div className="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
          {/* Toolbar */}
          <div className="p-4 border-b border-slate-800 flex flex-col sm:flex-row items-start sm:items-center gap-3">
            <div className="relative flex-1 min-w-0">
              <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500" />
              <input
                type="text"
                placeholder="Rechercher une entreprise..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="w-full pl-9 pr-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white placeholder-slate-500 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
              />
            </div>
            <div className="flex items-center gap-2 shrink-0">
              <div className="flex bg-slate-800 rounded-lg p-1 gap-1">
                {([
                  { key: 'tous', label: 'Tous' },
                  { key: 'actifs', label: 'Actifs' },
                  { key: 'alertes', label: 'Alertes', warn: true },
                  { key: 'expires', label: 'Expirés', danger: true },
                ] as { key: FilterTab; label: string; warn?: boolean; danger?: boolean }[]).map(({ key, label, warn, danger }) => (
                  <button
                    key={key}
                    onClick={() => setFilterTab(key)}
                    className={`px-3 py-1.5 rounded text-xs font-medium transition-colors ${
                      filterTab === key
                        ? danger ? 'bg-red-600 text-white'
                          : warn ? 'bg-orange-500 text-white'
                          : 'bg-purple-600 text-white'
                        : 'text-slate-400 hover:text-white'
                    }`}
                  >
                    {label}
                    {tabCounts[key] > 0 && (
                      <span className={`ml-1.5 ${filterTab === key ? 'text-white/70' : 'text-slate-500'}`}>
                        {tabCounts[key]}
                      </span>
                    )}
                  </button>
                ))}
              </div>
              <Link
                to="/super-admin/tenants/new"
                className="flex items-center gap-1.5 px-4 py-2 bg-purple-600 text-white text-sm rounded-lg hover:bg-purple-700 transition-colors whitespace-nowrap"
              >
                <Plus size={16} />
                Nouvelle entreprise
              </Link>
            </div>
          </div>

          {/* Table */}
          {loading ? (
            <div className="p-12 text-center text-slate-500">Chargement...</div>
          ) : filtered.length === 0 ? (
            <div className="p-12 text-center">
              <Building2 size={40} className="text-slate-700 mx-auto mb-3" />
              <p className="text-slate-500">Aucune entreprise trouvée</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-slate-800">
                    <th className="px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Entreprise</th>
                    <th className="px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Plan</th>
                    <th className="px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Admins</th>
                    <th className="px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Expiration</th>
                    <th className="px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Statut</th>
                    <th className="px-5 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-800">
                  {filtered.map((tenant) => {
                    const days = getDaysLeft(tenant.date_expiration);
                    const rowHighlight = days < 0 ? 'bg-red-950/20' : days <= 30 ? 'bg-orange-950/20' : '';
                    return (
                      <tr key={tenant.id} className={`hover:bg-slate-800/50 transition-colors ${rowHighlight}`}>
                        <td className="px-5 py-4">
                          <div className="flex items-center gap-3">
                            <div className="w-9 h-9 bg-purple-900 rounded-lg flex items-center justify-center shrink-0">
                              <span className="text-purple-300 text-sm font-bold">
                                {tenant.nom.charAt(0).toUpperCase()}
                              </span>
                            </div>
                            <div>
                              <p className="text-white font-medium text-sm">{tenant.nom}</p>
                              <code className="text-slate-500 text-xs">{tenant.slug}</code>
                            </div>
                          </div>
                        </td>
                        <td className="px-5 py-4"><PlanBadge plan={tenant.plan} /></td>
                        <td className="px-5 py-4">
                          <div className="flex items-center gap-1.5 text-slate-400 text-sm">
                            <Users size={14} />
                            {tenant.admin_users_count}
                          </div>
                        </td>
                        <td className="px-5 py-4">
                          <div className="flex items-center gap-2">
                            <ExpirationBadge dateStr={tenant.date_expiration} />
                            {tenant.renouvelable && (
                              <span title="Renouvelable">
                                <Clock size={12} className="text-slate-600" />
                              </span>
                            )}
                          </div>
                        </td>
                        <td className="px-5 py-4">
                          <button
                            onClick={() => toggleActif(tenant)}
                            disabled={togglingId === tenant.id}
                            className="flex items-center gap-1.5 text-sm transition-colors disabled:opacity-50"
                          >
                            {tenant.actif ? (
                              <><ToggleRight size={20} className="text-emerald-400" /><span className="text-emerald-400">Actif</span></>
                            ) : (
                              <><ToggleLeft size={20} className="text-slate-500" /><span className="text-slate-500">Inactif</span></>
                            )}
                          </button>
                        </td>
                        <td className="px-5 py-4 text-right">
                          <Link
                            to={`/super-admin/tenants/${tenant.id}`}
                            className="inline-flex items-center gap-1 text-purple-400 hover:text-purple-300 text-sm transition-colors"
                          >
                            Gérer <ChevronRight size={14} />
                          </Link>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}

          {/* Footer */}
          <div className="px-5 py-3 border-t border-slate-800 flex items-center justify-between">
            <p className="text-slate-600 text-xs">
              {filtered.length} entreprise{filtered.length !== 1 ? 's' : ''}
              {search && ` • Filtre: "${search}"`}
            </p>
            <div className="flex items-center gap-1 text-slate-600 text-xs">
              <TrendingUp size={12} />
              {stats?.tenants_actifs ?? 0}/{stats?.total_tenants ?? 0} actives
            </div>
          </div>
        </div>
      </main>
    </div>
  );
}
