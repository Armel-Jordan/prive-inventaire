import { useEffect, useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { Building2, Users, AlertTriangle, CheckCircle, Plus, LogOut, Shield } from 'lucide-react';

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

function getExpirationStatus(dateStr: string): { label: string; color: string; isExpired: boolean; isWarning: boolean } {
  const date = new Date(dateStr);
  const now = new Date();
  const diffDays = Math.ceil((date.getTime() - now.getTime()) / (1000 * 60 * 60 * 24));
  
  if (diffDays < 0) {
    return { label: 'Expiré', color: 'bg-red-100 text-red-700', isExpired: true, isWarning: false };
  } else if (diffDays <= 30) {
    return { label: `${diffDays}j restants`, color: 'bg-orange-100 text-orange-700', isExpired: false, isWarning: true };
  } else {
    return { label: new Date(dateStr).toLocaleDateString('fr-FR'), color: 'text-gray-500', isExpired: false, isWarning: false };
  }
}

function getAuth() {
  const stored = localStorage.getItem(STORAGE_KEY);
  if (!stored) return null;
  try {
    return JSON.parse(stored);
  } catch {
    return null;
  }
}

export default function SuperAdminDashboard() {
  const navigate = useNavigate();
  const [stats, setStats] = useState<Stats | null>(null);
  const [tenants, setTenants] = useState<Tenant[]>([]);
  const [loading, setLoading] = useState(true);
  const auth = getAuth();

  useEffect(() => {
    if (!auth) {
      navigate('/super-admin');
      return;
    }
    loadData();
  }, []);

  async function fetchApi(endpoint: string) {
    const response = await fetch(`${API_BASE_URL}${endpoint}`, {
      headers: {
        'Authorization': `Bearer ${auth?.token}`,
        'Accept': 'application/json',
      },
    });
    if (!response.ok) throw new Error('Erreur API');
    return response.json();
  }

  async function loadData() {
    try {
      const [statsData, tenantsData] = await Promise.all([
        fetchApi('/super-admin/stats'),
        fetchApi('/super-admin/tenants'),
      ]);
      setStats(statsData);
      setTenants(tenantsData);
    } catch (error) {
      console.error('Erreur:', error);
    } finally {
      setLoading(false);
    }
  }

  function handleLogout() {
    localStorage.removeItem(STORAGE_KEY);
    navigate('/super-admin');
  }

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-100 flex items-center justify-center">
        <div className="text-gray-500">Chargement...</div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-100">
      {/* Header */}
      <header className="bg-white shadow-sm">
        <div className="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Shield className="text-purple-600" size={28} />
            <h1 className="text-xl font-bold text-gray-800">Super Admin - Prise Inventaire</h1>
          </div>
          <div className="flex items-center gap-4">
            <span className="text-sm text-gray-600">{auth?.user?.nom}</span>
            <button
              onClick={handleLogout}
              className="flex items-center gap-2 px-3 py-2 text-red-600 hover:bg-red-50 rounded-lg"
            >
              <LogOut size={18} />
              Déconnexion
            </button>
          </div>
        </div>
      </header>

      <main className="max-w-7xl mx-auto px-4 py-8">
        {/* Stats */}
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
          <div className="bg-white rounded-xl shadow-sm p-6">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-blue-100 rounded-lg">
                <Building2 className="text-blue-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold">{stats?.total_tenants || 0}</p>
                <p className="text-sm text-gray-500">Entreprises</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-6">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-green-100 rounded-lg">
                <CheckCircle className="text-green-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold">{stats?.tenants_actifs || 0}</p>
                <p className="text-sm text-gray-500">Actives</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-6">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-purple-100 rounded-lg">
                <Users className="text-purple-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold">{stats?.total_admins || 0}</p>
                <p className="text-sm text-gray-500">Utilisateurs</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-6">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-red-100 rounded-lg">
                <AlertTriangle className="text-red-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold">{stats?.tenants_expires || 0}</p>
                <p className="text-sm text-gray-500">Expirées</p>
              </div>
            </div>
          </div>
        </div>

        {/* Tenants List */}
        <div className="bg-white rounded-xl shadow-sm">
          <div className="flex items-center justify-between p-4 border-b">
            <h2 className="text-lg font-semibold">Entreprises clientes</h2>
            <Link
              to="/super-admin/tenants/new"
              className="flex items-center gap-2 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700"
            >
              <Plus size={18} />
              Nouvelle entreprise
            </Link>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 border-b">
                <tr>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Entreprise</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Code</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Plan</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Admins</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Statut</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Expiration</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {tenants.map((tenant) => (
                  <tr key={tenant.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 font-medium">{tenant.nom}</td>
                    <td className="px-4 py-3">
                      <code className="bg-gray-100 px-2 py-1 rounded text-sm">{tenant.slug}</code>
                    </td>
                    <td className="px-4 py-3">
                      <span className={`px-2 py-1 rounded text-xs font-medium ${
                        tenant.plan === 'enterprise' ? 'bg-purple-100 text-purple-700' :
                        tenant.plan === 'pro' ? 'bg-blue-100 text-blue-700' :
                        'bg-gray-100 text-gray-700'
                      }`}>
                        {tenant.plan}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-sm">{tenant.admin_users_count}</td>
                    <td className="px-4 py-3">
                      <span className={`px-2 py-1 rounded text-xs font-medium ${
                        tenant.actif ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'
                      }`}>
                        {tenant.actif ? 'Actif' : 'Inactif'}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      {(() => {
                        const status = getExpirationStatus(tenant.date_expiration);
                        return (
                          <div className="flex items-center gap-2">
                            <span className={`px-2 py-1 rounded text-xs font-medium ${status.color}`}>
                              {status.label}
                            </span>
                            {tenant.renouvelable && (
                              <span className="text-xs text-gray-400" title="Renouvelable">↻</span>
                            )}
                          </div>
                        );
                      })()}
                    </td>
                    <td className="px-4 py-3">
                      <Link
                        to={`/super-admin/tenants/${tenant.id}`}
                        className="text-purple-600 hover:underline text-sm"
                      >
                        Gérer →
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </main>
    </div>
  );
}
