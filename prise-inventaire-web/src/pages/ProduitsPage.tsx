import { useEffect, useState, useRef } from 'react';
import { Search, Plus, X, Edit2, Trash2, Download, Upload, Filter } from 'lucide-react';
import { getProduits, createProduit, updateProduit, deleteProduit, getSecteurs, getConfiguration } from '@/services/api';
import type { Produit, Secteur } from '@/types';
import { useToast } from '@/hooks/useToast';
import Toasts from '@/components/Toasts';
import ConfirmModal from '@/components/ConfirmModal';

interface ProduitForm {
  numero: string;
  description: string;
  mesure: string;
  type: string;
  secteur_id: string;
}

const emptyForm: ProduitForm = { numero: '', description: '', mesure: 'UN', type: '', secteur_id: '' };

interface ConfigNumero {
  auto_increment: boolean;
  prefixe: string;
  suffixe: string;
  longueur: number;
  separateur: string;
  prochain_numero: number;
}

export default function ProduitsPage() {
  const [produits, setProduits] = useState<Produit[]>([]);
  const [secteurs, setSecteurs] = useState<Secteur[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [filterType, setFilterType] = useState('');
  const [filterSecteur, setFilterSecteur] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState<ProduitForm>(emptyForm);
  const [importing, setImporting] = useState(false);
  const [configNumero, setConfigNumero] = useState<ConfigNumero | null>(null);
  const [confirmId, setConfirmId] = useState<number | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const { toasts, toast, dismiss } = useToast();

  async function loadData() {
    try {
      const [produitsData, secteursData] = await Promise.all([getProduits(), getSecteurs()]);
      setProduits(produitsData);
      setSecteurs(secteursData);
    } catch {
      toast('Erreur lors du chargement', 'error');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadData();
    getConfiguration('produit').then(setConfigNumero).catch(() => {});
  }, []);

  // Unique types for filter
  const types = [...new Set(produits.map(p => p.type).filter(Boolean))].sort();

  const filtered = produits.filter(p => {
    const q = search.toLowerCase();
    const matchSearch = p.numero.toLowerCase().includes(q) || p.description.toLowerCase().includes(q);
    const matchType = !filterType || p.type === filterType;
    const matchSecteur = !filterSecteur || String(p.secteur_id) === filterSecteur;
    return matchSearch && matchType && matchSecteur;
  });

  function openCreate() {
    setForm(emptyForm);
    setEditingId(null);
    setShowModal(true);
  }

  function openEdit(produit: Produit) {
    setForm({
      numero: produit.numero,
      description: produit.description,
      mesure: produit.mesure,
      type: produit.type || '',
      secteur_id: produit.secteur_id?.toString() || '',
    });
    setEditingId(produit.id!);
    setShowModal(true);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    try {
      const payload = { ...form, secteur_id: form.secteur_id ? parseInt(form.secteur_id) : undefined };
      if (editingId) {
        await updateProduit(editingId, payload);
        toast('Produit modifié avec succès');
      } else {
        await createProduit(payload);
        toast('Produit créé avec succès');
      }
      setShowModal(false);
      loadData();
    } catch {
      toast('Erreur lors de la sauvegarde', 'error');
    }
  }

  async function handleDelete(id: number) {
    try {
      await deleteProduit(id);
      toast('Produit supprimé');
      loadData();
    } catch {
      toast('Erreur lors de la suppression', 'error');
    } finally {
      setConfirmId(null);
    }
  }

  function exportToCSV() {
    const headers = ['numero', 'description', 'secteur_code', 'mesure', 'type'];
    const csv = [
      headers.join(';'),
      ...produits.map(p => [p.numero, p.description, p.secteur?.code || '', p.mesure, p.type || ''].join(';'))
    ].join('\n');
    const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `produits_${new Date().toISOString().split('T')[0]}.csv`;
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
        if (row.numero && row.description) {
          try {
            const secteur = secteurs.find(s => s.code === row.secteur_code);
            await createProduit({ numero: row.numero, description: row.description, mesure: row.mesure || 'UN', type: row.type || '', secteur_id: secteur?.id });
            imported++;
          } catch { errors++; }
        }
      }
      toast(`${imported} produits importés${errors ? `, ${errors} erreurs` : ''}`, errors ? 'info' : 'success');
      loadData();
    } catch {
      toast('Erreur lors de l\'import', 'error');
    } finally {
      setImporting(false);
      if (fileInputRef.current) fileInputRef.current.value = '';
    }
  }

  const hasFilters = filterType || filterSecteur;

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Produits</h1>
          {!loading && (
            <p className="text-sm text-gray-500 mt-0.5">
              {filtered.length} produit{filtered.length !== 1 ? 's' : ''}{filtered.length !== produits.length ? ` sur ${produits.length}` : ''}
            </p>
          )}
        </div>
        <div className="flex gap-2">
          <button onClick={exportToCSV} disabled={produits.length === 0}
            className="flex items-center gap-2 px-4 py-2 border border-gray-300 text-gray-700 dark:border-gray-600 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
            <Download size={18} /> Exporter
          </button>
          <label className="flex items-center gap-2 px-4 py-2 border border-gray-300 text-gray-700 dark:border-gray-600 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors cursor-pointer">
            <Upload size={18} />
            {importing ? 'Import...' : 'Importer'}
            <input ref={fileInputRef} type="file" accept=".csv" onChange={handleImport} className="hidden" disabled={importing} />
          </label>
          <button onClick={openCreate}
            className="flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
            <Plus size={20} /> Ajouter
          </button>
        </div>
      </div>

      {/* Filtres */}
      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4 mb-6">
        <div className="flex flex-wrap gap-3">
          <div className="relative flex-1 min-w-48">
            <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <input type="text" placeholder="Rechercher par numéro ou description..."
              value={search} onChange={(e) => setSearch(e.target.value)}
              className="w-full pl-9 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 dark:text-white text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500" />
          </div>
          <div className="relative">
            <Filter size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <select value={filterType} onChange={e => setFilterType(e.target.value)}
              className="pl-8 pr-8 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 dark:text-white text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 appearance-none">
              <option value="">Tous les types</option>
              {types.map(t => <option key={t} value={t}>{t}</option>)}
            </select>
          </div>
          <div className="relative">
            <Filter size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <select value={filterSecteur} onChange={e => setFilterSecteur(e.target.value)}
              className="pl-8 pr-8 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 dark:text-white text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 appearance-none">
              <option value="">Tous les secteurs</option>
              {secteurs.map(s => <option key={s.id} value={String(s.id)}>{s.code} — {s.nom}</option>)}
            </select>
          </div>
          {hasFilters && (
            <button onClick={() => { setFilterType(''); setFilterSecteur(''); }}
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
          <div className="p-6 text-gray-500">Aucun produit trouvé</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 dark:bg-gray-700 border-b dark:border-gray-600">
                <tr>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Numéro</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Description</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Secteur</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Unité</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Type</th>
                  <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y dark:divide-gray-700">
                {filtered.map((produit) => (
                  <tr key={produit.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                    <td className="px-4 py-3 text-sm font-mono font-medium dark:text-gray-200">{produit.numero}</td>
                    <td className="px-4 py-3 text-sm dark:text-gray-200">{produit.description}</td>
                    <td className="px-4 py-3 text-sm">
                      {produit.secteur
                        ? <span className="bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 px-2 py-1 rounded text-xs font-medium">{produit.secteur.code}</span>
                        : <span className="text-gray-400 text-xs">-</span>}
                    </td>
                    <td className="px-4 py-3 text-sm dark:text-gray-300">{produit.mesure}</td>
                    <td className="px-4 py-3 text-sm">
                      {produit.type && (
                        <span className="bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300 px-2 py-1 rounded text-xs font-medium">{produit.type}</span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <button onClick={() => openEdit(produit)} className="p-1 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded mr-2" title="Modifier">
                        <Edit2 size={16} />
                      </button>
                      <button onClick={() => setConfirmId(produit.id!)} className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded" title="Supprimer">
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
              <h2 className="text-lg font-semibold dark:text-white">{editingId ? 'Modifier le produit' : 'Nouveau produit'}</h2>
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
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description *</label>
                <input type="text" value={form.description} onChange={e => setForm({ ...form, description: e.target.value })}
                  className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" required />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Secteur *</label>
                <select value={form.secteur_id} onChange={e => setForm({ ...form, secteur_id: e.target.value })}
                  className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" required>
                  <option value="">Sélectionner un secteur...</option>
                  {secteurs.map(s => <option key={s.id} value={s.id}>{s.code} - {s.nom}</option>)}
                </select>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Unité de mesure</label>
                  <select value={form.mesure} onChange={e => setForm({ ...form, mesure: e.target.value })}
                    className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                    <option value="UN">UN (Unité)</option>
                    <option value="KG">KG (Kilogramme)</option>
                    <option value="L">L (Litre)</option>
                    <option value="M">M (Mètre)</option>
                    <option value="M2">M² (Mètre carré)</option>
                    <option value="M3">M³ (Mètre cube)</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Type</label>
                  <input type="text" value={form.type} onChange={e => setForm({ ...form, type: e.target.value })}
                    className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white"
                    placeholder="ex: PIECE, LIQUIDE"
                    list="types-list" />
                  <datalist id="types-list">
                    {types.map(t => <option key={t} value={t} />)}
                  </datalist>
                </div>
              </div>
              <div className="flex gap-3 pt-2">
                <button type="button" onClick={() => setShowModal(false)}
                  className="flex-1 px-4 py-2 border dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-300">Annuler</button>
                <button type="submit" className="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                  {editingId ? 'Modifier' : 'Créer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      <ConfirmModal
        open={confirmId !== null}
        title="Supprimer le produit"
        message="Cette action est irréversible. Le produit sera définitivement supprimé."
        onConfirm={() => confirmId !== null && handleDelete(confirmId)}
        onCancel={() => setConfirmId(null)}
      />

      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}
