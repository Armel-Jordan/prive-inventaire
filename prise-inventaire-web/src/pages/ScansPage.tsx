import { useEffect, useState, useMemo } from 'react';
import { Pencil, Trash2, Search, X, Plus, Download, Calendar, Filter, CheckSquare, Square } from 'lucide-react';
import { getScans, createScan, updateScan, deleteScan, getSecteurs, getEmployes, getProduits } from '@/services/api';
import type { InventaireScan, Secteur, Employe, Produit } from '@/types';
import { formatDate } from '@/lib/utils';

interface ScanForm {
  numero: string;
  type: string;
  quantite: string;
  unite_mesure: string;
  employe: string;
  secteur: string;
}

const emptyForm: ScanForm = { numero: '', type: '', quantite: '', unite_mesure: 'UN', employe: '', secteur: '' };

export default function ScansPage() {
  const [scans, setScans] = useState<InventaireScan[]>([]);
  const [secteurs, setSecteurs] = useState<Secteur[]>([]);
  const [employes, setEmployes] = useState<Employe[]>([]);
  const [produits, setProduits] = useState<Produit[]>([]);
  const [loading, setLoading] = useState(true);
  const [editingScan, setEditingScan] = useState<InventaireScan | null>(null);
  const [editQuantite, setEditQuantite] = useState('');
  const [filterSecteur, setFilterSecteur] = useState('');
  const [filterEmploye, setFilterEmploye] = useState('');
  const [filterProduit, setFilterProduit] = useState('');
  const [filterDateDebut, setFilterDateDebut] = useState('');
  const [filterDateFin, setFilterDateFin] = useState('');
  const [showFilters, setShowFilters] = useState(false);
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [form, setForm] = useState<ScanForm>(emptyForm);
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
  const [isDeleting, setIsDeleting] = useState(false);

  // Sélection par lot
  const toggleSelect = (id: number) => {
    const newSet = new Set(selectedIds);
    if (newSet.has(id)) {
      newSet.delete(id);
    } else {
      newSet.add(id);
    }
    setSelectedIds(newSet);
  };

  const toggleSelectAll = () => {
    if (selectedIds.size === filteredScans.length) {
      setSelectedIds(new Set());
    } else {
      setSelectedIds(new Set(filteredScans.map(s => s.id)));
    }
  };

  const handleDeleteSelected = async () => {
    if (selectedIds.size === 0) return;
    if (!confirm(`Supprimer ${selectedIds.size} scan(s) sélectionné(s) ?`)) return;
    
    setIsDeleting(true);
    try {
      await Promise.all(Array.from(selectedIds).map(id => deleteScan(id)));
      setSelectedIds(new Set());
      loadData();
    } catch (error) {
      console.error('Erreur suppression lot:', error);
      alert('Erreur lors de la suppression');
    } finally {
      setIsDeleting(false);
    }
  };

  // Filtrage local des scans
  const filteredScans = useMemo(() => {
    return scans.filter(scan => {
      // Filtre par produit (recherche texte)
      if (filterProduit && !scan.numero.toLowerCase().includes(filterProduit.toLowerCase())) {
        return false;
      }
      // Filtre par date début
      if (filterDateDebut) {
        const scanDate = new Date(scan.date_saisie).toISOString().split('T')[0];
        if (scanDate < filterDateDebut) return false;
      }
      // Filtre par date fin
      if (filterDateFin) {
        const scanDate = new Date(scan.date_saisie).toISOString().split('T')[0];
        if (scanDate > filterDateFin) return false;
      }
      return true;
    });
  }, [scans, filterProduit, filterDateDebut, filterDateFin]);

  const hasActiveFilters = filterSecteur || filterEmploye || filterProduit || filterDateDebut || filterDateFin;

  function resetFilters() {
    setFilterSecteur('');
    setFilterEmploye('');
    setFilterProduit('');
    setFilterDateDebut('');
    setFilterDateFin('');
  }

  async function loadData() {
    setLoading(true);
    try {
      const [scansData, secteursData, employesData, produitsData] = await Promise.all([
        getScans({ secteur: filterSecteur || undefined, employe: filterEmploye || undefined }),
        getSecteurs(),
        getEmployes(),
        getProduits(),
      ]);
      setScans(scansData);
      setSecteurs(secteursData);
      setEmployes(employesData);
      setProduits(produitsData);
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }

  function openCreate() {
    setForm(emptyForm);
    setShowCreateModal(true);
  }

  function exportToCSV() {
    const headers = ['Numéro', 'Type', 'Quantité', 'Unité', 'Employé', 'Secteur', 'Date'];
    const csvContent = [
      headers.join(';'),
      ...filteredScans.map(s => [
        s.numero,
        s.type || '',
        s.quantite,
        s.unite_mesure,
        s.employe,
        s.secteur,
        formatDate(s.date_saisie)
      ].join(';'))
    ].join('\n');
    
    const blob = new Blob(['\ufeff' + csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    const dateStr = new Date().toISOString().split('T')[0];
    const filterStr = hasActiveFilters ? '_filtre' : '';
    link.download = `inventaire_${dateStr}${filterStr}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  }

  async function handleCreateScan(e: React.FormEvent) {
    e.preventDefault();
    try {
      await createScan({
        numero: form.numero,
        type: form.type,
        quantite: parseFloat(form.quantite),
        unite_mesure: form.unite_mesure,
        employe: form.employe,
        secteur: form.secteur,
      });
      setShowCreateModal(false);
      loadData();
    } catch (error) {
      console.error('Erreur:', error);
      alert('Erreur lors de la création');
    }
  }

  useEffect(() => {
    loadData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filterSecteur, filterEmploye]);

  async function handleUpdate() {
    if (!editingScan) return;
    try {
      await updateScan(editingScan.id, parseFloat(editQuantite));
      setEditingScan(null);
      loadData();
    } catch (error) {
      console.error('Erreur modification:', error);
    }
  }

  async function handleDelete(id: number) {
    if (!confirm('Supprimer ce scan ?')) return;
    try {
      await deleteScan(id);
      loadData();
    } catch (error) {
      console.error('Erreur suppression:', error);
    }
  }

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
        <h1 className="text-2xl font-bold text-gray-800">Gestion des Inventaires</h1>
        <div className="flex gap-2">
          <button
            onClick={exportToCSV}
            className="flex items-center gap-2 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors"
            disabled={filteredScans.length === 0}
          >
            <Download size={18} />
            Exporter ({filteredScans.length})
          </button>
          <button
            onClick={openCreate}
            className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
          >
            <Plus size={20} />
            Nouveau scan
          </button>
        </div>
      </div>

      {/* Filtres */}
      <div className="bg-white rounded-xl shadow-sm p-4 mb-6">
        <div className="flex items-center justify-between mb-3">
          <div className="flex items-center gap-2">
            <Filter size={20} className="text-gray-400" />
            <span className="font-medium text-gray-700">Recherche avancée</span>
            {hasActiveFilters && (
              <span className="bg-blue-100 text-blue-700 text-xs px-2 py-0.5 rounded-full">
                {[filterSecteur, filterEmploye, filterProduit, filterDateDebut, filterDateFin].filter(Boolean).length} filtre(s)
              </span>
            )}
          </div>
          <button
            onClick={() => setShowFilters(!showFilters)}
            className="text-sm text-blue-600 hover:text-blue-700"
          >
            {showFilters ? 'Masquer' : 'Afficher'} les filtres
          </button>
        </div>

        {showFilters && (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 pt-3 border-t">
            {/* Recherche produit */}
            <div>
              <label className="block text-xs text-gray-500 mb-1">Produit</label>
              <div className="relative">
                <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                <input
                  type="text"
                  value={filterProduit}
                  onChange={(e) => setFilterProduit(e.target.value)}
                  placeholder="Rechercher..."
                  className="w-full border rounded-lg pl-9 pr-3 py-2 text-sm"
                />
              </div>
            </div>

            {/* Secteur */}
            <div>
              <label className="block text-xs text-gray-500 mb-1">Secteur</label>
              <select
                value={filterSecteur}
                onChange={(e) => setFilterSecteur(e.target.value)}
                className="w-full border rounded-lg px-3 py-2 text-sm"
              >
                <option value="">Tous</option>
                {secteurs.map((s) => (
                  <option key={s.id} value={s.code}>{s.code} - {s.nom}</option>
                ))}
              </select>
            </div>

            {/* Employé */}
            <div>
              <label className="block text-xs text-gray-500 mb-1">Employé</label>
              <select
                value={filterEmploye}
                onChange={(e) => setFilterEmploye(e.target.value)}
                className="w-full border rounded-lg px-3 py-2 text-sm"
              >
                <option value="">Tous</option>
                {employes.map((e) => (
                  <option key={e.numero} value={e.numero}>{e.nom}</option>
                ))}
              </select>
            </div>

            {/* Date début */}
            <div>
              <label className="block text-xs text-gray-500 mb-1">
                <Calendar size={12} className="inline mr-1" />
                Date début
              </label>
              <input
                type="date"
                value={filterDateDebut}
                onChange={(e) => setFilterDateDebut(e.target.value)}
                className="w-full border rounded-lg px-3 py-2 text-sm"
              />
            </div>

            {/* Date fin */}
            <div>
              <label className="block text-xs text-gray-500 mb-1">
                <Calendar size={12} className="inline mr-1" />
                Date fin
              </label>
              <input
                type="date"
                value={filterDateFin}
                onChange={(e) => setFilterDateFin(e.target.value)}
                className="w-full border rounded-lg px-3 py-2 text-sm"
              />
            </div>
          </div>
        )}

        {hasActiveFilters && (
          <div className="flex items-center justify-between mt-3 pt-3 border-t">
            <span className="text-sm text-gray-500">
              {filteredScans.length} résultat(s) trouvé(s)
            </span>
            <button
              onClick={resetFilters}
              className="text-sm text-red-600 hover:text-red-700 flex items-center gap-1"
            >
              <X size={16} /> Réinitialiser les filtres
            </button>
          </div>
        )}
      </div>

      {/* Barre d'actions par lot */}
      {selectedIds.size > 0 && (
        <div className="bg-blue-50 border border-blue-200 rounded-xl p-4 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <CheckSquare className="text-blue-600" size={20} />
            <span className="font-medium text-blue-800">
              {selectedIds.size} scan(s) sélectionné(s)
            </span>
          </div>
          <div className="flex gap-2">
            <button
              onClick={() => setSelectedIds(new Set())}
              className="px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-100 rounded-lg"
            >
              Désélectionner
            </button>
            <button
              onClick={handleDeleteSelected}
              disabled={isDeleting}
              className="px-3 py-1.5 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50 flex items-center gap-1"
            >
              <Trash2 size={14} />
              {isDeleting ? 'Suppression...' : 'Supprimer la sélection'}
            </button>
          </div>
        </div>
      )}

      {/* Table */}
      <div className="bg-white rounded-xl shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-6 text-gray-500">Chargement...</div>
        ) : filteredScans.length === 0 ? (
          <div className="p-6 text-gray-500">Aucun scan trouvé</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 border-b">
                <tr>
                  <th className="px-4 py-3 text-left">
                    <button
                      onClick={toggleSelectAll}
                      className="p-1 hover:bg-gray-200 rounded"
                      title={selectedIds.size === filteredScans.length ? 'Tout désélectionner' : 'Tout sélectionner'}
                    >
                      {selectedIds.size === filteredScans.length && filteredScans.length > 0 ? (
                        <CheckSquare size={18} className="text-blue-600" />
                      ) : (
                        <Square size={18} className="text-gray-400" />
                      )}
                    </button>
                  </th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Numéro</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Type</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Quantité</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Unité</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Secteur</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Employé</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Date</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {filteredScans.map((scan) => (
                  <tr key={scan.id} className={`hover:bg-gray-50 ${selectedIds.has(scan.id) ? 'bg-blue-50' : ''}`}>
                    <td className="px-4 py-3">
                      <button
                        onClick={() => toggleSelect(scan.id)}
                        className="p-1 hover:bg-gray-200 rounded"
                      >
                        {selectedIds.has(scan.id) ? (
                          <CheckSquare size={18} className="text-blue-600" />
                        ) : (
                          <Square size={18} className="text-gray-400" />
                        )}
                      </button>
                    </td>
                    <td className="px-4 py-3 text-sm font-mono">{scan.numero}</td>
                    <td className="px-4 py-3 text-sm">{scan.type}</td>
                    <td className="px-4 py-3 text-sm font-medium">{scan.quantite}</td>
                    <td className="px-4 py-3 text-sm">{scan.unite_mesure}</td>
                    <td className="px-4 py-3 text-sm">
                      <span className="bg-purple-100 text-purple-700 px-2 py-1 rounded text-xs font-medium">
                        {scan.secteur}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-sm">{scan.employe}</td>
                    <td className="px-4 py-3 text-sm text-gray-500">{formatDate(scan.date_saisie)}</td>
                    <td className="px-4 py-3">
                      <div className="flex gap-2">
                        <button
                          onClick={() => { setEditingScan(scan); setEditQuantite(String(scan.quantite)); }}
                          className="p-1.5 text-blue-600 hover:bg-blue-50 rounded"
                          title="Modifier"
                        >
                          <Pencil size={16} />
                        </button>
                        <button
                          onClick={() => handleDelete(scan.id)}
                          className="p-1.5 text-red-600 hover:bg-red-50 rounded"
                          title="Supprimer"
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

      {/* Modal édition */}
      {editingScan && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl p-6 w-full max-w-md mx-4">
            <h2 className="text-lg font-bold mb-4">Modifier le scan</h2>
            <p className="text-sm text-gray-500 mb-4">
              Produit: <span className="font-mono">{editingScan.numero}</span>
            </p>
            <div className="mb-4">
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Quantité
              </label>
              <input
                type="number"
                value={editQuantite}
                onChange={(e) => setEditQuantite(e.target.value)}
                className="w-full border rounded-lg px-3 py-2"
                min="0"
                step="0.01"
              />
            </div>
            <div className="flex gap-3 justify-end">
              <button
                onClick={() => setEditingScan(null)}
                className="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg"
              >
                Annuler
              </button>
              <button
                onClick={handleUpdate}
                className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
              >
                Enregistrer
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal création */}
      {showCreateModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4">
            <div className="flex items-center justify-between p-4 border-b">
              <h2 className="text-lg font-semibold">Nouveau scan d'inventaire</h2>
              <button onClick={() => setShowCreateModal(false)} className="p-1 hover:bg-gray-100 rounded">
                <X size={20} />
              </button>
            </div>
            <form onSubmit={handleCreateScan} className="p-4 space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Produit *</label>
                <select
                  value={form.numero}
                  onChange={(e) => {
                    const prod = produits.find(p => p.numero === e.target.value);
                    setForm({ ...form, numero: e.target.value, type: prod?.type || '', unite_mesure: prod?.mesure || 'UN' });
                  }}
                  className="w-full px-3 py-2 border rounded-lg"
                  required
                >
                  <option value="">Sélectionner un produit</option>
                  {produits.map(p => (
                    <option key={p.id} value={p.numero}>{p.numero} - {p.description}</option>
                  ))}
                </select>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Quantité *</label>
                  <input
                    type="number"
                    value={form.quantite}
                    onChange={(e) => setForm({ ...form, quantite: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                    min="0"
                    step="0.01"
                    required
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Unité</label>
                  <input
                    type="text"
                    value={form.unite_mesure}
                    onChange={(e) => setForm({ ...form, unite_mesure: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg bg-gray-50"
                    readOnly
                  />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Secteur *</label>
                  <select
                    value={form.secteur}
                    onChange={(e) => setForm({ ...form, secteur: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                    required
                  >
                    <option value="">Sélectionner</option>
                    {secteurs.map(s => (
                      <option key={s.id} value={s.code}>{s.code} - {s.nom}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Employé *</label>
                  <select
                    value={form.employe}
                    onChange={(e) => setForm({ ...form, employe: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                    required
                  >
                    <option value="">Sélectionner</option>
                    {employes.map(e => (
                      <option key={e.id} value={e.numero}>{e.nom}</option>
                    ))}
                  </select>
                </div>
              </div>
              <div className="flex gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => setShowCreateModal(false)}
                  className="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50"
                >
                  Annuler
                </button>
                <button
                  type="submit"
                  className="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                >
                  Enregistrer
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
