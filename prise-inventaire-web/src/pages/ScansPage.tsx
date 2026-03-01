import { useEffect, useState } from 'react';
import { Pencil, Trash2, Search, X } from 'lucide-react';
import { getScans, updateScan, deleteScan, getSecteurs, getEmployes } from '@/services/api';
import type { InventaireScan, Secteur, Employe } from '@/types';
import { formatDate } from '@/lib/utils';

export default function ScansPage() {
  const [scans, setScans] = useState<InventaireScan[]>([]);
  const [secteurs, setSecteurs] = useState<Secteur[]>([]);
  const [employes, setEmployes] = useState<Employe[]>([]);
  const [loading, setLoading] = useState(true);
  const [editingScan, setEditingScan] = useState<InventaireScan | null>(null);
  const [editQuantite, setEditQuantite] = useState('');
  const [filterSecteur, setFilterSecteur] = useState('');
  const [filterEmploye, setFilterEmploye] = useState('');

  async function loadData() {
    setLoading(true);
    try {
      const [scansData, secteursData, employesData] = await Promise.all([
        getScans({ secteur: filterSecteur || undefined, employe: filterEmploye || undefined }),
        getSecteurs(),
        getEmployes(),
      ]);
      setScans(scansData);
      setSecteurs(secteursData);
      setEmployes(employesData);
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
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
      <h1 className="text-2xl font-bold text-gray-800 mb-6">Gestion des Inventaires</h1>

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
    </div>
  );
}
