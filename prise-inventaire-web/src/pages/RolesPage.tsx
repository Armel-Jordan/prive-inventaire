import { useEffect, useState } from 'react';
import { Shield, Plus, Edit2, Trash2, Save, X, Check } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const STORAGE_KEY = 'prise_auth';

function getAuthHeaders(): Record<string, string> {
  const headers: Record<string, string> = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
  const stored = localStorage.getItem(STORAGE_KEY);
  if (stored) {
    try {
      const data = JSON.parse(stored);
      if (data.token) headers['Authorization'] = `Bearer ${data.token}`;
      if (data.tenant?.slug) headers['X-Tenant-Slug'] = data.tenant.slug;
    } catch { /* ignore */ }
  }
  return headers;
}

interface Permission {
  can_view: boolean;
  can_create: boolean;
  can_edit: boolean;
  can_delete: boolean;
}

interface Role {
  id: number;
  nom: string;
  description: string;
  is_system: boolean;
  permissions: Record<string, Permission>;
}

const MODULE_LABELS: Record<string, string> = {
  dashboard: 'Tableau de bord',
  inventaires: 'Inventaires',
  statistiques: 'Statistiques',
  comparaison: 'Comparaison',
  alertes: 'Alertes Stock',
  historique: 'Historique',
  tracabilite: 'Traçabilité',
  relocalisation: 'Relocalisation',
  planification: 'Planification',
  approbations: 'Approbations',
  rapports: 'Rapports',
  inventaire_tournant: 'Inventaire Tournant',
  produits: 'Produits',
  secteurs: 'Secteurs',
  employes: 'Employés',
  roles: 'Gestion des rôles',
  fournisseurs: 'Fournisseurs',
  commandes_fournisseur: 'Commandes Fournisseur',
  receptions: 'Réceptions',
  clients: 'Clients',
  commandes_client: 'Commandes Client',
  factures: 'Factures',
  bons_livraison: 'Bons de Livraison',
  camions: 'Camions',
  tournees: 'Tournées',
  zones_preparation: 'Zones Préparation',
  devis: 'Devis',
  comptabilite: 'Comptabilité',
  previsions_stock: 'Prévisions Stock',
};

const MODULES = Object.keys(MODULE_LABELS);

export default function RolesPage() {
  const { isAdmin } = usePermissions();
  const [roles, setRoles] = useState<Role[]>([]);
  const [loading, setLoading] = useState(true);
  const [editingRole, setEditingRole] = useState<Role | null>(null);
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [newRole, setNewRole] = useState({ nom: '', description: '' });
  const [newPermissions, setNewPermissions] = useState<Record<string, Permission>>({});
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  useEffect(() => {
    loadRoles();
  }, []);

  async function loadRoles() {
    setLoading(true);
    try {
      const res = await fetch(`${API_BASE_URL}/roles-custom`, { headers: getAuthHeaders() });
      if (res.ok) {
        setRoles(await res.json());
      }
    } catch (error) {
      console.error('Erreur chargement rôles:', error);
    } finally {
      setLoading(false);
    }
  }

  function initNewPermissions() {
    const perms: Record<string, Permission> = {};
    MODULES.forEach(m => {
      perms[m] = { can_view: false, can_create: false, can_edit: false, can_delete: false };
    });
    setNewPermissions(perms);
  }

  async function handleCreateRole() {
    if (!newRole.nom.trim()) return;
    setSaving(true);
    try {
      const res = await fetch(`${API_BASE_URL}/roles-custom`, {
        method: 'POST',
        headers: getAuthHeaders(),
        body: JSON.stringify({ ...newRole, permissions: newPermissions }),
      });
      if (res.ok) {
        setMessage({ type: 'success', text: 'Rôle créé avec succès' });
        setShowCreateModal(false);
        setNewRole({ nom: '', description: '' });
        loadRoles();
      } else {
        const data = await res.json();
        setMessage({ type: 'error', text: data.message || 'Erreur lors de la création' });
      }
    } catch {
      setMessage({ type: 'error', text: 'Erreur de connexion' });
    } finally {
      setSaving(false);
    }
  }

  async function handleUpdateRole() {
    if (!editingRole) return;
    setSaving(true);
    try {
      const res = await fetch(`${API_BASE_URL}/roles-custom/${editingRole.id}`, {
        method: 'PUT',
        headers: getAuthHeaders(),
        body: JSON.stringify({
          nom: editingRole.nom,
          description: editingRole.description,
          permissions: editingRole.permissions,
        }),
      });
      if (res.ok) {
        setMessage({ type: 'success', text: 'Rôle mis à jour avec succès' });
        setEditingRole(null);
        loadRoles();
      } else {
        const data = await res.json();
        setMessage({ type: 'error', text: data.message || 'Erreur lors de la mise à jour' });
      }
    } catch {
      setMessage({ type: 'error', text: 'Erreur de connexion' });
    } finally {
      setSaving(false);
    }
  }

  async function handleDeleteRole(roleId: number) {
    if (!confirm('Êtes-vous sûr de vouloir supprimer ce rôle ?')) return;
    try {
      const res = await fetch(`${API_BASE_URL}/roles-custom/${roleId}`, {
        method: 'DELETE',
        headers: getAuthHeaders(),
      });
      if (res.ok) {
        setMessage({ type: 'success', text: 'Rôle supprimé avec succès' });
        loadRoles();
      } else {
        const data = await res.json();
        setMessage({ type: 'error', text: data.message || 'Erreur lors de la suppression' });
      }
    } catch {
      setMessage({ type: 'error', text: 'Erreur de connexion' });
    }
  }

  function togglePermission(
    perms: Record<string, Permission>,
    setPerms: (p: Record<string, Permission>) => void,
    module: string,
    type: keyof Permission
  ) {
    const current = perms[module] || { can_view: false, can_create: false, can_edit: false, can_delete: false };
    setPerms({
      ...perms,
      [module]: { ...current, [type]: !current[type] },
    });
  }

  if (!isAdmin()) {
    return (
      <div className="bg-red-50 border border-red-200 rounded-lg p-6 text-center">
        <Shield className="mx-auto text-red-500 mb-4" size={48} />
        <h2 className="text-lg font-semibold text-red-800 mb-2">Accès refusé</h2>
        <p className="text-red-600">Seuls les administrateurs peuvent gérer les rôles.</p>
      </div>
    );
  }

  if (loading) {
    return <div className="text-gray-500">Chargement des rôles...</div>;
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Gestion des Rôles</h1>
          <p className="text-gray-500 dark:text-gray-400">Créez et gérez les rôles et permissions</p>
        </div>
        <button
          onClick={() => { initNewPermissions(); setShowCreateModal(true); }}
          className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
        >
          <Plus size={18} />
          Nouveau rôle
        </button>
      </div>

      {message && (
        <div className={`p-4 rounded-lg ${message.type === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>
          {message.text}
        </div>
      )}

      {/* Liste des rôles */}
      <div className="grid gap-4">
        {roles.map((role) => (
          <div key={role.id} className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-6">
            <div className="flex items-center justify-between mb-4">
              <div>
                <h3 className="text-lg font-semibold text-gray-800 dark:text-white flex items-center gap-2">
                  <Shield size={20} className="text-blue-600" />
                  {role.nom}
                  {role.is_system && (
                    <span className="text-xs bg-gray-200 dark:bg-gray-700 px-2 py-1 rounded">Système</span>
                  )}
                </h3>
                <p className="text-sm text-gray-500 dark:text-gray-400">{role.description}</p>
              </div>
              <div className="flex gap-2">
                <button
                  onClick={() => setEditingRole({ ...role })}
                  className="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded-lg"
                >
                  <Edit2 size={18} />
                </button>
                {!role.is_system && (
                  <button
                    onClick={() => handleDeleteRole(role.id)}
                    className="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded-lg"
                  >
                    <Trash2 size={18} />
                  </button>
                )}
              </div>
            </div>

            {/* Permissions résumées */}
            <div className="flex flex-wrap gap-2">
              {MODULES.filter(m => role.permissions[m]?.can_view).map(m => (
                <span key={m} className="text-xs bg-green-100 text-green-700 px-2 py-1 rounded">
                  {MODULE_LABELS[m]}
                </span>
              ))}
            </div>
          </div>
        ))}
      </div>

      {/* Modal de création */}
      {showCreateModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-4xl max-h-[90vh] overflow-hidden">
            <div className="flex items-center justify-between p-4 border-b dark:border-gray-700">
              <h2 className="text-lg font-semibold dark:text-white">Créer un nouveau rôle</h2>
              <button onClick={() => setShowCreateModal(false)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded">
                <X size={20} />
              </button>
            </div>
            <div className="p-4 overflow-y-auto max-h-[70vh]">
              <div className="grid grid-cols-2 gap-4 mb-6">
                <div>
                  <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Nom du rôle</label>
                  <input
                    type="text"
                    value={newRole.nom}
                    onChange={(e) => setNewRole({ ...newRole, nom: e.target.value })}
                    className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 dark:text-white"
                    placeholder="ex: superviseur"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Description</label>
                  <input
                    type="text"
                    value={newRole.description}
                    onChange={(e) => setNewRole({ ...newRole, description: e.target.value })}
                    className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 dark:text-white"
                    placeholder="Description du rôle"
                  />
                </div>
              </div>

              <h3 className="font-medium text-gray-800 dark:text-white mb-3">Permissions par module</h3>
              <table className="w-full text-sm">
                <thead className="bg-gray-50 dark:bg-gray-700">
                  <tr>
                    <th className="px-3 py-2 text-left">Module</th>
                    <th className="px-3 py-2 text-center">Voir</th>
                    <th className="px-3 py-2 text-center">Créer</th>
                    <th className="px-3 py-2 text-center">Modifier</th>
                    <th className="px-3 py-2 text-center">Supprimer</th>
                  </tr>
                </thead>
                <tbody className="divide-y dark:divide-gray-700">
                  {MODULES.map(module => (
                    <tr key={module} className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                      <td className="px-3 py-2 font-medium dark:text-white">{MODULE_LABELS[module]}</td>
                      {(['can_view', 'can_create', 'can_edit', 'can_delete'] as const).map(perm => (
                        <td key={perm} className="px-3 py-2 text-center">
                          <button
                            onClick={() => togglePermission(newPermissions, setNewPermissions, module, perm)}
                            className={`w-6 h-6 rounded ${newPermissions[module]?.[perm] ? 'bg-green-500 text-white' : 'bg-gray-200 dark:bg-gray-600'}`}
                          >
                            {newPermissions[module]?.[perm] && <Check size={14} className="mx-auto" />}
                          </button>
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="p-4 border-t dark:border-gray-700 flex justify-end gap-2">
              <button onClick={() => setShowCreateModal(false)} className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-white">
                Annuler
              </button>
              <button
                onClick={handleCreateRole}
                disabled={saving || !newRole.nom.trim()}
                className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
              >
                <Save size={18} />
                {saving ? 'Création...' : 'Créer le rôle'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal d'édition */}
      {editingRole && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-4xl max-h-[90vh] overflow-hidden">
            <div className="flex items-center justify-between p-4 border-b dark:border-gray-700">
              <h2 className="text-lg font-semibold dark:text-white">Modifier le rôle: {editingRole.nom}</h2>
              <button onClick={() => setEditingRole(null)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded">
                <X size={20} />
              </button>
            </div>
            <div className="p-4 overflow-y-auto max-h-[70vh]">
              <div className="grid grid-cols-2 gap-4 mb-6">
                <div>
                  <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Nom du rôle</label>
                  <input
                    type="text"
                    value={editingRole.nom}
                    onChange={(e) => setEditingRole({ ...editingRole, nom: e.target.value })}
                    disabled={editingRole.is_system}
                    className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 dark:text-white disabled:opacity-50"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Description</label>
                  <input
                    type="text"
                    value={editingRole.description || ''}
                    onChange={(e) => setEditingRole({ ...editingRole, description: e.target.value })}
                    className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 dark:text-white"
                  />
                </div>
              </div>

              <h3 className="font-medium text-gray-800 dark:text-white mb-3">Permissions par module</h3>
              <table className="w-full text-sm">
                <thead className="bg-gray-50 dark:bg-gray-700">
                  <tr>
                    <th className="px-3 py-2 text-left">Module</th>
                    <th className="px-3 py-2 text-center">Voir</th>
                    <th className="px-3 py-2 text-center">Créer</th>
                    <th className="px-3 py-2 text-center">Modifier</th>
                    <th className="px-3 py-2 text-center">Supprimer</th>
                  </tr>
                </thead>
                <tbody className="divide-y dark:divide-gray-700">
                  {MODULES.map(module => (
                    <tr key={module} className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                      <td className="px-3 py-2 font-medium dark:text-white">{MODULE_LABELS[module]}</td>
                      {(['can_view', 'can_create', 'can_edit', 'can_delete'] as const).map(perm => (
                        <td key={perm} className="px-3 py-2 text-center">
                          <button
                            onClick={() => {
                              const current = editingRole.permissions[module] || { can_view: false, can_create: false, can_edit: false, can_delete: false };
                              setEditingRole({
                                ...editingRole,
                                permissions: {
                                  ...editingRole.permissions,
                                  [module]: { ...current, [perm]: !current[perm] },
                                },
                              });
                            }}
                            className={`w-6 h-6 rounded ${editingRole.permissions[module]?.[perm] ? 'bg-green-500 text-white' : 'bg-gray-200 dark:bg-gray-600'}`}
                          >
                            {editingRole.permissions[module]?.[perm] && <Check size={14} className="mx-auto" />}
                          </button>
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="p-4 border-t dark:border-gray-700 flex justify-end gap-2">
              <button onClick={() => setEditingRole(null)} className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-white">
                Annuler
              </button>
              <button
                onClick={handleUpdateRole}
                disabled={saving}
                className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
              >
                <Save size={18} />
                {saving ? 'Enregistrement...' : 'Enregistrer'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
