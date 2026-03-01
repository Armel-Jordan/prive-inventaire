import { useEffect, useState } from 'react';
import { Pencil, Trash2, Search, X, Plus, Download } from 'lucide-react';
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
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [form, setForm] = useState<ScanForm>(emptyForm);

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
    const headers = ['numero', 'type', 'quantite', 'unite_mesure', 'employe', 'secteur', 'date_saisie'];
    const csvContent = [
      headers.join(';'),
      ...scans.map(s => [
        s.numero,
        s.type || '',
        s.quantite,
        s.unite_mesure,
        s.employe,
        s.secteur,
        s.date_saisie
      ].join(';'))
    ].join('\n');
    
    const blob = new Blob(['\ufeff' + csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `inventaire_${new Date().toISOString().split('T')[0]}.csv`;
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
            disabled={scans.length === 0}
          >
            <Download size={18} />
            Exporter
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
        <div className="flex flex-wrap gap-4 items-center">
          <div className="flex items-center gap-2">
            <Search size={20} className="text-gray-400" />
            <span className="text-sm text-gray-600">Filtres:</span>
          </div>
          
          <select
            value={filterSecteur}
            onChange={(e) => setFilterSecteur(e.target.value)}
            className="border rounded-lg px-3 py-2 text-sm"
          >
            <option value="">Tous les secteurs</option>
            {secteurs.map((s) => (
              <option key={s.id} value={s.code}>{s.code} - {s.nom}</option>
            ))}
          </select>

          <select
            value={filterEmploye}
            onChange={(e) => setFilterEmploye(e.target.value)}
            className="border rounded-lg px-3 py-2 text-sm"
          >
            <option value="">Tous les employés</option>
            {employes.map((e) => (
              <option key={e.numero} value={e.numero}>{e.nom}</option>
            ))}
          </select>

          {(filterSecteur || filterEmploye) && (
            <button
              onClick={() => { setFilterSecteur(''); setFilterEmploye(''); }}
              className="text-sm text-gray-500 hover:text-gray-700 flex items-center gap-1"
            >
              <X size={16} /> Réinitialiser
            </button>
          )}
        </div>
      </div>

      {/* Table */}
      <div className="bg-white rounded-xl shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-6 text-gray-500">Chargement...</div>
        ) : scans.length === 0 ? (
          <div className="p-6 text-gray-500">Aucun scan trouvé</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 border-b">
                <tr>
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
                {scans.map((scan) => (
                  <tr key={scan.id} className="hover:bg-gray-50">
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
