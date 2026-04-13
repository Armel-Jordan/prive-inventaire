import { useEffect, useState } from 'react';
import { useNavigate, useParams, Link } from 'react-router-dom';
import {
  ArrowLeft, Edit2, Trash2, X, UserPlus, Shield, Calendar,
  CheckCircle, AlertTriangle, RefreshCw, Save, Users, Info,
  ToggleLeft, ToggleRight, Clock,
} from 'lucide-react';

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const STORAGE_KEY = 'prise_super_admin';

interface Tenant {
  id: number;
  nom: string;
  slug: string;
  plan: string;
  actif: boolean;
  date_expiration: string;
  renouvelable: boolean;
  duree_abonnement: number;
}

interface Admin {
  id: number;
  nom: string;
  email: string;
  role: string;
  actif: boolean;
  derniere_connexion?: string;
  created_at: string;
}

interface AdminForm {
  nom: string;
  email: string;
  password: string;
  role: 'admin' | 'manager' | 'user';
}

const emptyForm: AdminForm = { nom: '', email: '', password: '', role: 'admin' };

function getDaysLeft(dateStr: string): number {
  return Math.ceil((new Date(dateStr).getTime() - Date.now()) / 86400000);
}

function PlanBadge({ plan }: { plan: string }) {
  const styles: Record<string, string> = {
    enterprise: 'bg-purple-100 text-purple-700',
    pro: 'bg-blue-100 text-blue-700',
    starter: 'bg-gray-100 text-gray-600',
  };
  return (
    <span className={`px-2.5 py-1 rounded-full text-xs font-medium ${styles[plan] ?? styles.starter}`}>
      {plan.charAt(0).toUpperCase() + plan.slice(1)}
    </span>
  );
}

function getAuth() {
  const stored = localStorage.getItem(STORAGE_KEY);
  if (!stored) return null;
  try { return JSON.parse(stored); } catch { return null; }
}

type ActiveTab = 'infos' | 'admins';

export default function SuperAdminTenantPage() {
  const navigate = useNavigate();
  const { tenantId } = useParams();
  const [tenant, setTenant] = useState<Tenant | null>(null);
  const [admins, setAdmins] = useState<Admin[]>([]);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState<ActiveTab>('infos');
  const [showAdminModal, setShowAdminModal] = useState(false);
  const [showRenewModal, setShowRenewModal] = useState(false);
  const [editingAdminId, setEditingAdminId] = useState<number | null>(null);
  const [form, setForm] = useState<AdminForm>(emptyForm);
  const [renewDuree, setRenewDuree] = useState<1 | 3 | 5>(1);
  const [saving, setSaving] = useState(false);
  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; msg: string } | null>(null);
  // Tenant edit state
  const [editNom, setEditNom] = useState('');
  const [editPlan, setEditPlan] = useState<string>('starter');
  const [editRenouvelable, setEditRenouvelable] = useState(true);
  const [savingTenant, setSavingTenant] = useState(false);
  const auth = getAuth();

  useEffect(() => {
    if (!auth) { navigate('/super-admin'); return; }
    loadData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tenantId]);

  useEffect(() => {
    if (tenant) {
      setEditNom(tenant.nom);
      setEditPlan(tenant.plan);
      setEditRenouvelable(tenant.renouvelable);
    }
  }, [tenant]);

  function showFeedback(type: 'success' | 'error', msg: string) {
    setFeedback({ type, msg });
    setTimeout(() => setFeedback(null), 3000);
  }

  async function fetchApi(endpoint: string, options?: RequestInit) {
    const res = await fetch(`${API_BASE_URL}${endpoint}`, {
      ...options,
      headers: {
        'Authorization': `Bearer ${auth?.token}`,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        ...options?.headers,
      },
    });
    if (!res.ok) throw new Error('Erreur API');
    return res.json();
  }

  async function loadData() {
    setLoading(true);
    try {
      const data = await fetchApi(`/super-admin/tenants/${tenantId}/admins`);
      setTenant(data.tenant);
      setAdmins(data.admins);
    } catch { /* ignore */ }
    finally { setLoading(false); }
  }

  async function saveTenant() {
    if (!tenant) return;
    setSavingTenant(true);
    try {
      const data = await fetchApi(`/super-admin/tenants/${tenant.id}`, {
        method: 'PUT',
        body: JSON.stringify({ nom: editNom, plan: editPlan, renouvelable: editRenouvelable }),
      });
      setTenant(data.tenant);
      showFeedback('success', 'Tenant mis à jour');
    } catch {
      showFeedback('error', 'Erreur lors de la mise à jour');
    } finally {
      setSavingTenant(false);
    }
  }

  async function toggleActif() {
    if (!tenant) return;
    try {
      const data = await fetchApi(`/super-admin/tenants/${tenant.id}`, {
        method: 'PUT',
        body: JSON.stringify({ actif: !tenant.actif }),
      });
      setTenant(data.tenant);
      showFeedback('success', `Tenant ${data.tenant.actif ? 'activé' : 'désactivé'}`);
    } catch {
      showFeedback('error', 'Erreur');
    }
  }

  function openCreate() { setEditingAdminId(null); setForm(emptyForm); setShowAdminModal(true); }
  function openEdit(admin: Admin) {
    setEditingAdminId(admin.id);
    setForm({ nom: admin.nom, email: admin.email, password: '', role: admin.role as AdminForm['role'] });
    setShowAdminModal(true);
  }

  async function handleSubmitAdmin(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    try {
      if (editingAdminId) {
        const payload: Partial<AdminForm> = { nom: form.nom, email: form.email, role: form.role };
        if (form.password) payload.password = form.password;
        await fetchApi(`/super-admin/tenants/${tenantId}/admins/${editingAdminId}`, {
          method: 'PUT',
          body: JSON.stringify(payload),
        });
        showFeedback('success', 'Administrateur mis à jour');
      } else {
        if (!form.password) { showFeedback('error', 'Le mot de passe est requis'); return; }
        await fetchApi(`/super-admin/tenants/${tenantId}/admins`, {
          method: 'POST',
          body: JSON.stringify(form),
        });
        showFeedback('success', 'Administrateur créé');
      }
      setShowAdminModal(false);
      loadData();
    } catch {
      showFeedback('error', 'Erreur lors de la sauvegarde');
    } finally {
      setSaving(false);
    }
  }

  async function handleDeleteAdmin(adminId: number) {
    if (!confirm('Voulez-vous vraiment supprimer cet administrateur ?')) return;
    try {
      await fetchApi(`/super-admin/tenants/${tenantId}/admins/${adminId}`, { method: 'DELETE' });
      setAdmins(prev => prev.filter(a => a.id !== adminId));
      showFeedback('success', 'Administrateur supprimé');
    } catch {
      showFeedback('error', 'Erreur lors de la suppression');
    }
  }

  async function handleRenew() {
    setSaving(true);
    try {
      await fetchApi(`/super-admin/tenants/${tenantId}/renew`, {
        method: 'POST',
        body: JSON.stringify({ duree_abonnement: renewDuree }),
      });
      setShowRenewModal(false);
      showFeedback('success', 'Abonnement renouvelé avec succès');
      loadData();
    } catch {
      showFeedback('error', 'Erreur lors du renouvellement');
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-950 flex items-center justify-center">
        <div className="text-slate-400">Chargement...</div>
      </div>
    );
  }

  const days = tenant ? getDaysLeft(tenant.date_expiration) : 0;
  const isExpired = days < 0;
  const isWarning = !isExpired && days <= 30;

  return (
    <div className="min-h-screen bg-slate-950">
      {/* Header */}
      <header className="bg-slate-900 border-b border-slate-800 sticky top-0 z-10">
        <div className="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
          <div className="flex items-center gap-4">
            <Link
              to="/super-admin/dashboard"
              className="flex items-center gap-2 text-slate-400 hover:text-white transition-colors text-sm"
            >
              <ArrowLeft size={18} />
              Dashboard
            </Link>
            <span className="text-slate-700">/</span>
            <span className="text-white font-medium">{tenant?.nom}</span>
          </div>
          <div className="flex items-center gap-2">
            <div className="p-2 bg-purple-600 rounded-lg">
              <Shield size={18} className="text-white" />
            </div>
            <span className="text-white font-medium text-sm">Super Admin</span>
          </div>
        </div>
      </header>

      {/* Feedback toast */}
      {feedback && (
        <div className={`fixed top-20 right-6 z-50 px-4 py-3 rounded-lg shadow-lg text-sm font-medium transition-all ${
          feedback.type === 'success' ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white'
        }`}>
          {feedback.msg}
        </div>
      )}

      <main className="max-w-7xl mx-auto px-6 py-8 space-y-6">
        {/* Tenant Hero Card */}
        <div className="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
          <div className="p-6 flex items-center justify-between flex-wrap gap-4">
            <div className="flex items-center gap-4">
              <div className="w-14 h-14 bg-purple-900 rounded-xl flex items-center justify-center text-2xl font-bold text-purple-300">
                {tenant?.nom.charAt(0).toUpperCase()}
              </div>
              <div>
                <div className="flex items-center gap-2 mb-1">
                  <h1 className="text-xl font-bold text-white">{tenant?.nom}</h1>
                  <PlanBadge plan={tenant?.plan ?? 'starter'} />
                  {tenant?.actif ? (
                    <span className="px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-900 text-emerald-300">Actif</span>
                  ) : (
                    <span className="px-2 py-0.5 rounded-full text-xs font-medium bg-red-900 text-red-300">Inactif</span>
                  )}
                </div>
                <code className="text-slate-500 text-sm">{tenant?.slug}</code>
              </div>
            </div>
            <div className="flex items-center gap-3">
              {isExpired ? (
                <span className="flex items-center gap-1.5 px-3 py-1.5 bg-red-900 text-red-300 rounded-lg text-sm">
                  <AlertTriangle size={14} /> Expiré
                </span>
              ) : isWarning ? (
                <span className="flex items-center gap-1.5 px-3 py-1.5 bg-orange-900 text-orange-300 rounded-lg text-sm">
                  <Clock size={14} /> {days}j restants
                </span>
              ) : (
                <span className="flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 text-slate-300 rounded-lg text-sm">
                  <Calendar size={14} /> {new Date(tenant?.date_expiration ?? '').toLocaleDateString('fr-FR')}
                </span>
              )}
              {tenant?.renouvelable && (
                <button
                  onClick={() => setShowRenewModal(true)}
                  className="flex items-center gap-1.5 px-3 py-1.5 bg-emerald-700 text-white rounded-lg text-sm hover:bg-emerald-600 transition-colors"
                >
                  <RefreshCw size={14} /> Renouveler
                </button>
              )}
            </div>
          </div>

          {/* Tabs */}
          <div className="border-t border-slate-800 flex">
            {([
              { key: 'infos', label: 'Informations', icon: Info },
              { key: 'admins', label: `Administrateurs (${admins.length})`, icon: Users },
            ] as { key: ActiveTab; label: string; icon: typeof Info }[]).map(({ key, label, icon: Icon }) => (
              <button
                key={key}
                onClick={() => setActiveTab(key)}
                className={`flex items-center gap-2 px-5 py-3 text-sm font-medium border-b-2 transition-colors ${
                  activeTab === key
                    ? 'border-purple-500 text-purple-400 bg-slate-800/50'
                    : 'border-transparent text-slate-500 hover:text-slate-300'
                }`}
              >
                <Icon size={16} />
                {label}
              </button>
            ))}
          </div>
        </div>

        {/* Tab: Informations */}
        {activeTab === 'infos' && tenant && (
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {/* Edit Form */}
            <div className="bg-slate-900 border border-slate-800 rounded-xl p-6 space-y-5">
              <h2 className="text-white font-semibold">Modifier le tenant</h2>
              <div>
                <label className="block text-sm text-slate-400 mb-1">Nom de l'entreprise</label>
                <input
                  type="text"
                  value={editNom}
                  onChange={(e) => setEditNom(e.target.value)}
                  className="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
                />
              </div>
              <div>
                <label className="block text-sm text-slate-400 mb-2">Plan</label>
                <div className="grid grid-cols-3 gap-2">
                  {['starter', 'pro', 'enterprise'].map(plan => (
                    <button
                      key={plan}
                      type="button"
                      onClick={() => setEditPlan(plan)}
                      className={`py-2 rounded-lg border text-sm font-medium transition-all ${
                        editPlan === plan
                          ? 'border-purple-500 bg-purple-900 text-purple-300'
                          : 'border-slate-700 text-slate-400 hover:border-slate-600'
                      }`}
                    >
                      {plan.charAt(0).toUpperCase() + plan.slice(1)}
                    </button>
                  ))}
                </div>
              </div>
              <div className="flex items-center justify-between p-3 bg-slate-800 rounded-lg">
                <div>
                  <p className="text-sm text-white font-medium">Abonnement renouvelable</p>
                  <p className="text-xs text-slate-500">Le client peut renouveler à l'expiration</p>
                </div>
                <button onClick={() => setEditRenouvelable(!editRenouvelable)}>
                  {editRenouvelable
                    ? <ToggleRight size={28} className="text-purple-400" />
                    : <ToggleLeft size={28} className="text-slate-600" />}
                </button>
              </div>
              <button
                onClick={saveTenant}
                disabled={savingTenant}
                className="w-full flex items-center justify-center gap-2 py-2.5 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 transition-colors text-sm font-medium"
              >
                <Save size={16} />
                {savingTenant ? 'Sauvegarde...' : 'Sauvegarder les modifications'}
              </button>
            </div>

            {/* Status & Info */}
            <div className="space-y-4">
              <div className="bg-slate-900 border border-slate-800 rounded-xl p-6 space-y-4">
                <h2 className="text-white font-semibold">Statut & Abonnement</h2>
                <div className="space-y-3">
                  <div className="flex items-center justify-between py-2 border-b border-slate-800">
                    <span className="text-slate-400 text-sm">Statut</span>
                    <button
                      onClick={toggleActif}
                      className="flex items-center gap-1.5 text-sm"
                    >
                      {tenant.actif ? (
                        <><ToggleRight size={22} className="text-emerald-400" /><span className="text-emerald-400">Actif</span></>
                      ) : (
                        <><ToggleLeft size={22} className="text-slate-500" /><span className="text-slate-500">Inactif</span></>
                      )}
                    </button>
                  </div>
                  <div className="flex items-center justify-between py-2 border-b border-slate-800">
                    <span className="text-slate-400 text-sm">Date d'expiration</span>
                    <span className="text-white text-sm">
                      {new Date(tenant.date_expiration).toLocaleDateString('fr-FR')}
                    </span>
                  </div>
                  <div className="flex items-center justify-between py-2 border-b border-slate-800">
                    <span className="text-slate-400 text-sm">Durée abonnement</span>
                    <span className="text-white text-sm">{tenant.duree_abonnement} an{tenant.duree_abonnement > 1 ? 's' : ''}</span>
                  </div>
                  <div className="flex items-center justify-between py-2 border-b border-slate-800">
                    <span className="text-slate-400 text-sm">Administrateurs</span>
                    <span className="text-white text-sm">{admins.length}</span>
                  </div>
                  <div className="flex items-center justify-between py-2">
                    <span className="text-slate-400 text-sm">Slug</span>
                    <code className="text-purple-400 text-xs bg-slate-800 px-2 py-1 rounded">{tenant.slug}</code>
                  </div>
                </div>
              </div>

              {/* Expiration alert */}
              {(isExpired || isWarning) && (
                <div className={`rounded-xl p-4 flex items-start gap-3 ${isExpired ? 'bg-red-950 border border-red-800' : 'bg-orange-950 border border-orange-800'}`}>
                  <AlertTriangle size={18} className={isExpired ? 'text-red-400 shrink-0 mt-0.5' : 'text-orange-400 shrink-0 mt-0.5'} />
                  <div>
                    <p className={`text-sm font-medium ${isExpired ? 'text-red-300' : 'text-orange-300'}`}>
                      {isExpired ? 'Abonnement expiré' : `Expiration dans ${days} jour${days > 1 ? 's' : ''}`}
                    </p>
                    <p className={`text-xs mt-0.5 ${isExpired ? 'text-red-500' : 'text-orange-500'}`}>
                      {isExpired
                        ? "L'accès des utilisateurs est bloqué."
                        : 'Pensez à renouveler l\'abonnement.'}
                    </p>
                    {tenant.renouvelable && (
                      <button
                        onClick={() => setShowRenewModal(true)}
                        className={`mt-2 text-xs font-medium underline ${isExpired ? 'text-red-400' : 'text-orange-400'}`}
                      >
                        Renouveler maintenant →
                      </button>
                    )}
                  </div>
                </div>
              )}
            </div>
          </div>
        )}

        {/* Tab: Admins */}
        {activeTab === 'admins' && (
          <div className="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <div className="flex items-center justify-between p-5 border-b border-slate-800">
              <h2 className="text-white font-semibold">Administrateurs</h2>
              <button
                onClick={openCreate}
                className="flex items-center gap-1.5 px-4 py-2 bg-purple-600 text-white text-sm rounded-lg hover:bg-purple-700 transition-colors"
              >
                <UserPlus size={16} />
                Ajouter
              </button>
            </div>
            {admins.length === 0 ? (
              <div className="p-12 text-center">
                <Users size={40} className="text-slate-700 mx-auto mb-3" />
                <p className="text-slate-500 text-sm">Aucun administrateur. Créez le premier admin pour cette entreprise.</p>
                <button onClick={openCreate} className="mt-4 px-4 py-2 bg-purple-600 text-white text-sm rounded-lg hover:bg-purple-700">
                  Créer un admin
                </button>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b border-slate-800">
                      <th className="px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Admin</th>
                      <th className="px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Rôle</th>
                      <th className="px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Statut</th>
                      <th className="px-5 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Dernière connexion</th>
                      <th className="px-5 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800">
                    {admins.map((admin) => (
                      <tr key={admin.id} className="hover:bg-slate-800/50 transition-colors">
                        <td className="px-5 py-4">
                          <div className="flex items-center gap-3">
                            <div className="w-8 h-8 bg-slate-700 rounded-full flex items-center justify-center text-xs font-bold text-slate-300">
                              {admin.nom.charAt(0).toUpperCase()}
                            </div>
                            <div>
                              <p className="text-white text-sm font-medium">{admin.nom}</p>
                              <p className="text-slate-500 text-xs">{admin.email}</p>
                            </div>
                          </div>
                        </td>
                        <td className="px-5 py-4">
                          <span className={`px-2 py-0.5 rounded text-xs font-medium ${
                            admin.role === 'admin' ? 'bg-purple-900 text-purple-300' :
                            admin.role === 'manager' ? 'bg-blue-900 text-blue-300' :
                            'bg-slate-700 text-slate-300'
                          }`}>
                            {admin.role}
                          </span>
                        </td>
                        <td className="px-5 py-4">
                          {admin.actif ? (
                            <span className="flex items-center gap-1 text-xs text-emerald-400">
                              <CheckCircle size={12} /> Actif
                            </span>
                          ) : (
                            <span className="text-xs text-red-400">Inactif</span>
                          )}
                        </td>
                        <td className="px-5 py-4 text-slate-500 text-xs">
                          {admin.derniere_connexion
                            ? new Date(admin.derniere_connexion).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })
                            : 'Jamais connecté'}
                        </td>
                        <td className="px-5 py-4 text-right">
                          <div className="flex items-center justify-end gap-2">
                            <button
                              onClick={() => openEdit(admin)}
                              className="p-1.5 text-slate-400 hover:text-blue-400 hover:bg-slate-800 rounded transition-colors"
                              title="Modifier"
                            >
                              <Edit2 size={15} />
                            </button>
                            <button
                              onClick={() => handleDeleteAdmin(admin.id)}
                              className="p-1.5 text-slate-400 hover:text-red-400 hover:bg-slate-800 rounded transition-colors"
                              title="Supprimer"
                            >
                              <Trash2 size={15} />
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
        )}
      </main>

      {/* Modal Admin */}
      {showAdminModal && (
        <div className="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
          <div className="bg-slate-900 border border-slate-700 rounded-xl shadow-2xl w-full max-w-md">
            <div className="flex items-center justify-between p-5 border-b border-slate-800">
              <h2 className="text-white font-semibold">
                {editingAdminId ? 'Modifier l\'administrateur' : 'Nouvel administrateur'}
              </h2>
              <button onClick={() => setShowAdminModal(false)} className="p-1.5 hover:bg-slate-800 rounded-lg text-slate-400 hover:text-white transition-colors">
                <X size={18} />
              </button>
            </div>
            <form onSubmit={handleSubmitAdmin} className="p-5 space-y-4">
              <div>
                <label className="block text-sm text-slate-400 mb-1">Nom *</label>
                <input
                  type="text"
                  value={form.nom}
                  onChange={(e) => setForm({ ...form, nom: e.target.value })}
                  className="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
                  required
                />
              </div>
              <div>
                <label className="block text-sm text-slate-400 mb-1">Email *</label>
                <input
                  type="email"
                  value={form.email}
                  onChange={(e) => setForm({ ...form, email: e.target.value })}
                  className="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
                  required
                />
              </div>
              <div>
                <label className="block text-sm text-slate-400 mb-1">
                  Mot de passe {editingAdminId ? '(laisser vide pour ne pas changer)' : '*'}
                </label>
                <input
                  type="password"
                  value={form.password}
                  onChange={(e) => setForm({ ...form, password: e.target.value })}
                  className="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
                  required={!editingAdminId}
                  minLength={6}
                  placeholder={editingAdminId ? 'Laisser vide' : 'Min. 6 caractères'}
                />
              </div>
              <div>
                <label className="block text-sm text-slate-400 mb-2">Rôle *</label>
                <div className="grid grid-cols-3 gap-2">
                  {(['admin', 'manager', 'user'] as const).map(role => (
                    <button
                      key={role}
                      type="button"
                      onClick={() => setForm({ ...form, role })}
                      className={`py-2 rounded-lg border text-sm font-medium transition-all ${
                        form.role === role
                          ? 'border-purple-500 bg-purple-900 text-purple-300'
                          : 'border-slate-700 text-slate-400 hover:border-slate-600'
                      }`}
                    >
                      {role.charAt(0).toUpperCase() + role.slice(1)}
                    </button>
                  ))}
                </div>
              </div>
              <div className="flex gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => setShowAdminModal(false)}
                  className="flex-1 py-2.5 border border-slate-700 text-slate-300 rounded-lg hover:bg-slate-800 text-sm transition-colors"
                >
                  Annuler
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="flex-1 py-2.5 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 text-sm font-medium transition-colors"
                >
                  {saving ? 'Sauvegarde...' : (editingAdminId ? 'Enregistrer' : 'Créer')}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal Renouvellement */}
      {showRenewModal && (
        <div className="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
          <div className="bg-slate-900 border border-slate-700 rounded-xl shadow-2xl w-full max-w-md">
            <div className="flex items-center justify-between p-5 border-b border-slate-800">
              <h2 className="text-white font-semibold">Renouveler l'abonnement</h2>
              <button onClick={() => setShowRenewModal(false)} className="p-1.5 hover:bg-slate-800 rounded-lg text-slate-400 hover:text-white transition-colors">
                <X size={18} />
              </button>
            </div>
            <div className="p-5 space-y-4">
              <p className="text-slate-400 text-sm">
                Choisissez la durée pour <span className="text-white font-medium">{tenant?.nom}</span>
              </p>
              <div className="grid grid-cols-3 gap-3">
                {([1, 3, 5] as const).map((duree) => (
                  <button
                    key={duree}
                    onClick={() => setRenewDuree(duree)}
                    className={`py-5 rounded-xl border-2 font-medium transition-all text-center ${
                      renewDuree === duree
                        ? 'border-emerald-500 bg-emerald-900 text-emerald-300'
                        : 'border-slate-700 text-slate-400 hover:border-slate-600'
                    }`}
                  >
                    <p className="text-2xl font-bold">{duree}</p>
                    <p className="text-xs mt-1">an{duree > 1 ? 's' : ''}</p>
                  </button>
                ))}
              </div>
              <div className="flex gap-3 pt-2">
                <button
                  onClick={() => setShowRenewModal(false)}
                  className="flex-1 py-2.5 border border-slate-700 text-slate-300 rounded-lg hover:bg-slate-800 text-sm transition-colors"
                >
                  Annuler
                </button>
                <button
                  onClick={handleRenew}
                  disabled={saving}
                  className="flex-1 py-2.5 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 disabled:opacity-50 text-sm font-medium transition-colors"
                >
                  {saving ? 'Renouvellement...' : 'Confirmer'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
