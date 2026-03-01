import { useEffect, useState } from 'react';
import { useNavigate, useParams, Link } from 'react-router-dom';
import { ArrowLeft, Edit2, Trash2, X, UserPlus } from 'lucide-react';

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const STORAGE_KEY = 'prise_super_admin';

interface Tenant {
  id: number;
  nom: string;
  slug: string;
  plan: string;
  actif: boolean;
  date_expiration: string;
}

interface Admin {
  id: number;
  nom: string;
  email: string;
  role: string;
  actif: boolean;
  derniere_connexion?: string;
}

interface AdminForm {
  nom: string;
  email: string;
  password: string;
  role: 'admin' | 'manager' | 'user';
}

const emptyForm: AdminForm = { nom: '', email: '', password: '', role: 'admin' };

function getAuth() {
  const stored = localStorage.getItem(STORAGE_KEY);
  if (!stored) return null;
  try {
    return JSON.parse(stored);
  } catch {
    return null;
  }
}

export default function SuperAdminTenantPage() {
  const navigate = useNavigate();
  const { tenantId } = useParams();
  const [tenant, setTenant] = useState<Tenant | null>(null);
  const [admins, setAdmins] = useState<Admin[]>([]);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState<AdminForm>(emptyForm);
  const auth = getAuth();

  useEffect(() => {
    if (!auth) {
      navigate('/super-admin');
      return;
    }
    loadData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tenantId]);

  async function fetchApi(endpoint: string, options?: RequestInit) {
    const response = await fetch(`${API_BASE_URL}${endpoint}`, {
      ...options,
      headers: {
        'Authorization': `Bearer ${auth?.token}`,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        ...options?.headers,
      },
    });
    if (!response.ok) throw new Error('Erreur API');
    return response.json();
  }

  async function loadData() {
    try {
      const data = await fetchApi(`/super-admin/tenants/${tenantId}/admins`);
      setTenant(data.tenant);
      setAdmins(data.admins);
    } catch (error) {
      console.error('Erreur:', error);
    } finally {
      setLoading(false);
    }
  }

  function openCreate() {
    setEditingId(null);
    setForm(emptyForm);
    setShowModal(true);
  }

  function openEdit(admin: Admin) {
    setEditingId(admin.id);
    setForm({
      nom: admin.nom,
      email: admin.email,
      password: '',
      role: admin.role as AdminForm['role'],
    });
    setShowModal(true);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    try {
      if (editingId) {
        const updateData: Partial<AdminForm> = {
          nom: form.nom,
          email: form.email,
          role: form.role,
        };
        if (form.password) {
          updateData.password = form.password;
        }
        await fetchApi(`/super-admin/tenants/${tenantId}/admins/${editingId}`, {
          method: 'PUT',
          body: JSON.stringify(updateData),
        });
      } else {
        if (!form.password) {
          alert('Le mot de passe est requis');
          return;
        }
        await fetchApi(`/super-admin/tenants/${tenantId}/admins`, {
          method: 'POST',
          body: JSON.stringify(form),
        });
      }
      setShowModal(false);
      loadData();
    } catch (error) {
      console.error('Erreur:', error);
      alert('Erreur lors de la sauvegarde');
    }
  }

  async function handleDelete(adminId: number) {
    if (!confirm('Voulez-vous vraiment supprimer cet administrateur ?')) return;
    try {
      await fetchApi(`/super-admin/tenants/${tenantId}/admins/${adminId}`, {
        method: 'DELETE',
      });
      loadData();
    } catch (error) {
      console.error('Erreur:', error);
      alert('Erreur lors de la suppression');
    }
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
      <header className="bg-white shadow-sm">
        <div className="max-w-7xl mx-auto px-4 py-4">
          <Link to="/super-admin/dashboard" className="flex items-center gap-2 text-gray-600 hover:text-gray-800">
            <ArrowLeft size={20} />
            Retour au dashboard
          </Link>
        </div>
      </header>

      <main className="max-w-7xl mx-auto px-4 py-8">
        {/* Tenant Info */}
        <div className="bg-white rounded-xl shadow-sm p-6 mb-6">
          <div className="flex items-center justify-between">
            <div>
              <h1 className="text-2xl font-bold text-gray-800">{tenant?.nom}</h1>
              <p className="text-gray-500">
                Code: <code className="bg-gray-100 px-2 py-1 rounded">{tenant?.slug}</code>
              </p>
            </div>
            <div className="flex items-center gap-4">
              <span className={`px-3 py-1 rounded-full text-sm font-medium ${
                tenant?.actif ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'
              }`}>
                {tenant?.actif ? 'Actif' : 'Inactif'}
              </span>
              <span className={`px-3 py-1 rounded-full text-sm font-medium ${
                tenant?.plan === 'enterprise' ? 'bg-purple-100 text-purple-700' :
                tenant?.plan === 'pro' ? 'bg-blue-100 text-blue-700' :
                'bg-gray-100 text-gray-700'
              }`}>
                {tenant?.plan}
              </span>
            </div>
          </div>
        </div>

        {/* Admins List */}
        <div className="bg-white rounded-xl shadow-sm">
          <div className="flex items-center justify-between p-4 border-b">
            <h2 className="text-lg font-semibold">Administrateurs</h2>
            <button
              onClick={openCreate}
              className="flex items-center gap-2 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700"
            >
              <UserPlus size={18} />
              Ajouter un admin
            </button>
          </div>
          {admins.length === 0 ? (
            <div className="p-6 text-center text-gray-500">
              Aucun administrateur. Créez le premier admin pour cette entreprise.
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead className="bg-gray-50 border-b">
                  <tr>
                    <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Nom</th>
                    <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Email</th>
                    <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Rôle</th>
                    <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Statut</th>
                    <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {admins.map((admin) => (
                    <tr key={admin.id} className="hover:bg-gray-50">
                      <td className="px-4 py-3 font-medium">{admin.nom}</td>
                      <td className="px-4 py-3 text-sm text-gray-600">{admin.email}</td>
                      <td className="px-4 py-3">
                        <span className={`px-2 py-1 rounded text-xs font-medium ${
                          admin.role === 'admin' ? 'bg-purple-100 text-purple-700' :
                          admin.role === 'manager' ? 'bg-blue-100 text-blue-700' :
                          'bg-gray-100 text-gray-700'
                        }`}>
                          {admin.role}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        <span className={`px-2 py-1 rounded text-xs font-medium ${
                          admin.actif ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'
                        }`}>
                          {admin.actif ? 'Actif' : 'Inactif'}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex gap-2">
                          <button
                            onClick={() => openEdit(admin)}
                            className="p-1.5 text-blue-600 hover:bg-blue-50 rounded"
                          >
                            <Edit2 size={16} />
                          </button>
                          <button
                            onClick={() => handleDelete(admin.id)}
                            className="p-1.5 text-red-600 hover:bg-red-50 rounded"
                          >
                            <Trash2 size={16} />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </main>

      {/* Modal */}
      {showModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
            <div className="flex items-center justify-between p-4 border-b">
              <h2 className="text-lg font-semibold">
                {editingId ? 'Modifier l\'administrateur' : 'Nouvel administrateur'}
              </h2>
              <button onClick={() => setShowModal(false)} className="p-1 hover:bg-gray-100 rounded">
                <X size={20} />
              </button>
            </div>
            <form onSubmit={handleSubmit} className="p-4 space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                <input
                  type="text"
                  value={form.nom}
                  onChange={(e) => setForm({ ...form, nom: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                <input
                  type="email"
                  value={form.email}
                  onChange={(e) => setForm({ ...form, email: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Mot de passe {editingId ? '(laisser vide pour ne pas changer)' : '*'}
                </label>
                <input
                  type="password"
                  value={form.password}
                  onChange={(e) => setForm({ ...form, password: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg"
                  required={!editingId}
                  minLength={6}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Rôle *</label>
                <select
                  value={form.role}
                  onChange={(e) => setForm({ ...form, role: e.target.value as AdminForm['role'] })}
                  className="w-full px-3 py-2 border rounded-lg"
                  required
                >
                  <option value="admin">Administrateur</option>
                  <option value="manager">Manager</option>
                  <option value="user">Utilisateur</option>
                </select>
              </div>
              <div className="flex gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => setShowModal(false)}
                  className="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50"
                >
                  Annuler
                </button>
                <button
                  type="submit"
                  className="flex-1 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700"
                >
                  {editingId ? 'Enregistrer' : 'Créer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
