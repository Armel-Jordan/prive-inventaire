import { useEffect, useState, useRef } from 'react';
import { Search, Plus, X, Edit2, Trash2, Download, Upload, Shield, ShieldOff, Filter } from 'lucide-react';
import { getEmployes, createEmploye, updateEmploye, deleteEmploye, getConfiguration } from '@/services/api';
import type { Employe } from '@/types';
import { useToast } from '@/hooks/useToast';
import Toasts from '@/components/Toasts';
import ConfirmModal from '@/components/ConfirmModal';

interface EmployeForm {
  numero: string;
  nom: string;
  prenom: string;
  email: string;
  role: string;
  password: string;
}

const emptyForm: EmployeForm = { numero: '', nom: '', prenom: '', email: '', role: '', password: '' };

const roleLabels: Record<string, { label: string; color: string }> = {
  admin:   { label: 'Admin',       color: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' },
  manager: { label: 'Manager',     color: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300' },
  user:    { label: 'Utilisateur', color: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' },
};

interface ConfigNumero {
  auto_increment: boolean;
  prefixe: string;
  suffixe: string;
  longueur: number;
  separateur: string;
  prochain_numero: number;
}

export default function EmployesPage() {
  const [employes, setEmployes] = useState<Employe[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [filterRole, setFilterRole] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState<EmployeForm>(emptyForm);
  const [importing, setImporting] = useState(false);
  const [configNumero, setConfigNumero] = useState<ConfigNumero | null>(null);
  const [confirmId, setConfirmId] = useState<number | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const { toasts, toast, dismiss } = useToast();

  async function loadData() {
    try {
      const data = await getEmployes();
      setEmployes(data);
    } catch {
      toast('Erreur lors du chargement', 'error');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadData();
    getConfiguration('employe').then(setConfigNumero).catch(() => {});
  }, []);

  const filtered = employes.filter(e => {
    const q = search.toLowerCase();
    const matchSearch = e.numero.toLowerCase().includes(q) || e.nom.toLowerCase().includes(q) || (e.prenom ?? '').toLowerCase().includes(q);
    const matchRole = !filterRole
      ? true
      : filterRole === 'none'
        ? !e.admin_user
        : e.admin_user?.role === filterRole;
    return matchSearch && matchRole;
  });

  function openCreate() {
    setForm(emptyForm);
    setEditingId(null);
    setShowModal(true);
  }

  function openEdit(employe: Employe) {
    setForm({
      numero: employe.numero,
      nom: employe.nom,
      prenom: employe.prenom || '',
      email: employe.email || '',
      role: employe.admin_user?.role || '',
      password: '',
    });
    setEditingId(employe.id);
    setShowModal(true);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    try {
      if (editingId) {
        await updateEmploye(editingId, form);
        toast('Employé modifié avec succès');
      } else {
        await createEmploye(form);
        toast('Employé créé avec succès');
      }
      setShowModal(false);
      loadData();
    } catch {
      toast('Erreur lors de la sauvegarde', 'error');
    }
  }

  async function handleDelete(id: number) {
    try {
      await deleteEmploye(id);
      toast('Employé désactivé');
      loadData();
    } catch {
      toast('Erreur lors de la suppression', 'error');
    } finally {
      setConfirmId(null);
    }
  }

  function exportToCSV() {
    const csv = [
      'numero;nom;prenom;email',
      ...employes.map(e => [e.numero, e.nom, e.prenom || '', e.email || ''].join(';'))
    ].join('\n');
    const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `employes_${new Date().toISOString().split('T')[0]}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    toast('Export téléchargé');
  }

  async function handleImport(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    if (!file) return;
    setImporting(true);
    try {
      const text = await file.text();
      const lines = text.split('\n').filter(l => l.trim());
      const headers = lines[0].split(';').map(h => h.trim().toLowerCase());
      let imported = 0, errors = 0;
      for (let i = 1; i < lines.length; i++) {
        const values = lines[i].split(';').map(v => v.trim());
        const row: Record<string, string> = {};
        headers.forEach((h, idx) => { row[h] = values[idx] || ''; });
        if (row.numero && row.nom) {
          try {
            await createEmploye({ numero: row.numero, nom: row.nom, prenom: row.prenom || '', email: row.email || '' });
            imported++;
          } catch { errors++; }
        }
      }
      toast(`${imported} employés importés${errors ? `, ${errors} erreurs` : ''}`, errors ? 'info' : 'success');
      loadData();
    } catch {
      toast('Erreur lors de l\'import', 'error');
    } finally {
      setImporting(false);
      if (fileInputRef.current) fileInputRef.current.value = '';
    }
  }

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Employés</h1>
          {!loading && (
            <p className="text-sm text-gray-500 mt-0.5">
              {filtered.length} employé{filtered.length !== 1 ? 's' : ''}{filtered.length !== employes.length ? ` sur ${employes.length}` : ''}
            </p>
          )}
        </div>
        <div className="flex gap-2">
          <button onClick={exportToCSV} disabled={employes.length === 0}
            className="flex items-center gap-2 px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
            <Download size={18} /> Exporter
          </button>
          <label className="flex items-center gap-2 px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors cursor-pointer">
            <Upload size={18} />
            {importing ? 'Import...' : 'Importer'}
            <input ref={fileInputRef} type="file" accept=".csv" onChange={handleImport} className="hidden" disabled={importing} />
          </label>
          <button onClick={openCreate}
            className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
            <Plus size={20} /> Ajouter
          </button>
        </div>
      </div>

      {/* Filtres */}
      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4 mb-6">
        <div className="flex flex-wrap gap-3">
          <div className="relative flex-1 min-w-48">
            <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <input type="text" placeholder="Rechercher par numéro ou nom..."
              value={search} onChange={(e) => setSearch(e.target.value)}
              className="w-full pl-9 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 dark:text-white text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
          </div>
          <div className="relative">
            <Filter size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <select value={filterRole} onChange={e => setFilterRole(e.target.value)}
              className="pl-8 pr-8 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 dark:text-white text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 appearance-none">
              <option value="">Tous les rôles</option>
              <option value="admin">Admin</option>
              <option value="manager">Manager</option>
              <option value="user">Utilisateur</option>
              <option value="none">Sans accès</option>
            </select>
          </div>
          {filterRole && (
            <button onClick={() => setFilterRole('')}
              className="px-3 py-2 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors flex items-center gap-1">
              <X size={14} /> Réinitialiser
            </button>
          )}
        </div>
      </div>

      {/* Table */}
      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-6 text-gray-500">Chargement...</div>
        ) : filtered.length === 0 ? (
          <div className="p-6 text-gray-500">Aucun employé trouvé</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 dark:bg-gray-700 border-b dark:border-gray-600">
                <tr>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Numéro</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Nom</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Prénom</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Email</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Accès app</th>
                  <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y dark:divide-gray-700">
                {filtered.map((employe) => (
                  <tr key={employe.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                    <td className="px-4 py-3 text-sm font-mono font-medium dark:text-gray-200">{employe.numero}</td>
                    <td className="px-4 py-3 text-sm dark:text-gray-200">{employe.nom}</td>
                    <td className="px-4 py-3 text-sm dark:text-gray-300">{employe.prenom || '-'}</td>
                    <td className="px-4 py-3 text-sm dark:text-gray-300">{employe.email || '-'}</td>
                    <td className="px-4 py-3">
                      {employe.admin_user ? (
                        <span className={`px-2 py-1 rounded text-xs font-medium ${roleLabels[employe.admin_user.role]?.color || 'bg-gray-100 text-gray-600'}`}>
                          {roleLabels[employe.admin_user.role]?.label || employe.admin_user.role}
                        </span>
                      ) : (
                        <span className="text-xs text-gray-400 flex items-center gap-1">
                          <ShieldOff size={12} /> Aucun
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <button onClick={() => openEdit(employe)} className="p-1 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded mr-2" title="Modifier">
                        <Edit2 size={16} />
                      </button>
                      <button onClick={() => setConfirmId(employe.id)} className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded" title="Désactiver">
                        <Trash2 size={16} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Modal créer/modifier */}
      {showModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-md mx-4">
            <div className="flex items-center justify-between p-4 border-b dark:border-gray-700">
              <h2 className="text-lg font-semibold dark:text-white">{editingId ? 'Modifier l\'employé' : 'Nouvel employé'}</h2>
              <button onClick={() => setShowModal(false)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded"><X size={20} /></button>
            </div>
            <form onSubmit={handleSubmit} className="p-4 space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                  Numéro {!editingId && configNumero?.auto_increment ? '(auto-généré)' : '*'}
                </label>
                <input type="text" value={form.numero} onChange={e => setForm({ ...form, numero: e.target.value })}
                  className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white disabled:bg-gray-100 dark:disabled:bg-gray-600"
                  required={!!editingId || !configNumero?.auto_increment} disabled={!!editingId}
                  placeholder={!editingId && configNumero?.auto_increment ? 'Généré automatiquement' : ''} />
                {!editingId && configNumero?.auto_increment && (
                  <p className="text-xs text-gray-500 mt-1">Format: {configNumero.prefixe}{configNumero.separateur || ''}{String(configNumero.prochain_numero).padStart(configNumero.longueur, '0')}{configNumero.suffixe ? (configNumero.separateur || '') + configNumero.suffixe : ''}</p>
                )}
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nom *</label>
                <input type="text" value={form.nom} onChange={e => setForm({ ...form, nom: e.target.value })}
                  className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" required />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Prénom</label>
                <input type="text" value={form.prenom} onChange={e => setForm({ ...form, prenom: e.target.value })}
                  className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
                <input type="email" value={form.email} onChange={e => setForm({ ...form, email: e.target.value })}
                  className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" />
              </div>

              <div className="border-t dark:border-gray-700 pt-4">
                <div className="flex items-center gap-2 mb-3">
                  <Shield size={16} className="text-blue-600" />
                  <span className="text-sm font-medium text-gray-700 dark:text-gray-300">Accès à l'application</span>
                </div>
                <div className="space-y-3">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Rôle</label>
                    <select value={form.role} onChange={e => setForm({ ...form, role: e.target.value, password: '' })}
                      className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                      <option value="">Aucun accès</option>
                      <option value="user">Utilisateur</option>
                      <option value="manager">Manager</option>
                      <option value="admin">Admin</option>
                      {editingId && form.role && <option value="remove">Supprimer l'accès</option>}
                    </select>
                  </div>
                  {form.role && form.role !== 'remove' && (
                    <div>
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Mot de passe {!editingId ? '*' : '(laisser vide pour ne pas changer)'}
                      </label>
                      <input type="password" value={form.password} onChange={e => setForm({ ...form, password: e.target.value })}
                        className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white"
                        required={!editingId} minLength={6} placeholder="Minimum 6 caractères" />
                    </div>
                  )}
                </div>
              </div>

              <div className="flex gap-3 pt-2">
                <button type="button" onClick={() => setShowModal(false)}
                  className="flex-1 px-4 py-2 border dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-300">Annuler</button>
                <button type="submit" className="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                  {editingId ? 'Modifier' : 'Créer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      <ConfirmModal
        open={confirmId !== null}
        title="Désactiver l'employé"
        message="L'employé sera désactivé. Son accès à l'application sera conservé mais il n'apparaîtra plus dans les listes."
        confirmLabel="Désactiver"
        onConfirm={() => confirmId !== null && handleDelete(confirmId)}
        onCancel={() => setConfirmId(null)}
      />

      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}
