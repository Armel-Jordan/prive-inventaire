import { useState, useEffect, useCallback } from 'react';
import { Plus, Edit, Trash2, Truck } from 'lucide-react';
import {
  getCamions,
  createCamion,
  updateCamion,
  deleteCamion,
} from '../services/api';
import type { Camion } from '../services/api';

export default function CamionsPage() {
  const [camions, setCamions] = useState<Camion[]>([]);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [editingCamion, setEditingCamion] = useState<Camion | null>(null);
  const [formData, setFormData] = useState({
    immatriculation: '',
    marque: '',
    modele: '',
    type: 'camion' as 'camionnette' | 'camion' | 'semi_remorque',
    capacite_kg: '',
    capacite_m3: '',
    date_controle_technique: '',
  });

  const loadCamions = useCallback(async () => {
    try {
      setLoading(true);
      const data = await getCamions();
      setCamions(data);
    } catch (error) {
      console.error('Erreur chargement camions:', error);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadCamions();
  }, [loadCamions]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      const data = {
        ...formData,
        capacite_kg: formData.capacite_kg ? parseInt(formData.capacite_kg) : undefined,
        capacite_m3: formData.capacite_m3 ? parseFloat(formData.capacite_m3) : undefined,
        date_controle_technique: formData.date_controle_technique || undefined,
      };

      if (editingCamion) {
        await updateCamion(editingCamion.id, data);
      } else {
        await createCamion(data);
      }
      setShowModal(false);
      resetForm();
      loadCamions();
    } catch (error) {
      console.error('Erreur sauvegarde camion:', error);
    }
  };

  const handleEdit = (camion: Camion) => {
    setEditingCamion(camion);
    setFormData({
      immatriculation: camion.immatriculation,
      marque: camion.marque || '',
      modele: camion.modele || '',
      type: camion.type,
      capacite_kg: camion.capacite_kg?.toString() || '',
      capacite_m3: camion.capacite_m3?.toString() || '',
      date_controle_technique: camion.date_controle_technique || '',
    });
    setShowModal(true);
  };

  const handleDelete = async (id: number) => {
    if (!confirm('Supprimer ce camion ?')) return;
    try {
      await deleteCamion(id);
      loadCamions();
    } catch (error) {
      console.error('Erreur suppression:', error);
    }
  };

  const resetForm = () => {
    setEditingCamion(null);
    setFormData({
      immatriculation: '',
      marque: '',
      modele: '',
      type: 'camion',
      capacite_kg: '',
      capacite_m3: '',
      date_controle_technique: '',
    });
  };

  const typeLabels = {
    camionnette: 'Camionnette',
    camion: 'Camion',
    semi_remorque: 'Semi-remorque',
  };

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Camions</h1>
        <button
          onClick={() => { resetForm(); setShowModal(true); }}
          className="flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700"
        >
          <Plus size={20} />
          Ajouter
        </button>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
        {loading ? (
          <div className="p-8 text-center text-gray-500">Chargement...</div>
        ) : camions.length === 0 ? (
          <div className="p-8 text-center text-gray-500">Aucun camion trouvé</div>
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50 dark:bg-gray-700">
              <tr>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Immatriculation</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Marque / Modèle</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Type</th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Capacité</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">CT</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Statut</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
              {camions.map((camion) => (
                <tr key={camion.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <Truck size={18} className="text-gray-400" />
                      <span className="font-mono font-medium text-gray-900 dark:text-white">{camion.immatriculation}</span>
                    </div>
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                    {camion.marque} {camion.modele}
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                    {typeLabels[camion.type]}
                  </td>
                  <td className="px-4 py-3 text-right text-sm text-gray-600 dark:text-gray-300">
                    {camion.capacite_kg && <div>{camion.capacite_kg} kg</div>}
                    {camion.capacite_m3 && <div>{camion.capacite_m3} m³</div>}
                  </td>
                  <td className="px-4 py-3 text-center text-sm text-gray-600 dark:text-gray-300">
                    {camion.date_controle_technique || '-'}
                  </td>
                  <td className="px-4 py-3 text-center">
                    <span className={`px-2 py-1 text-xs rounded-full ${
                      camion.actif
                        ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
                        : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
                    }`}>
                      {camion.actif ? 'Actif' : 'Inactif'}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex justify-center gap-2">
                      <button onClick={() => handleEdit(camion)} className="p-1 text-blue-600 hover:bg-blue-50 rounded">
                        <Edit size={18} />
                      </button>
                      <button onClick={() => handleDelete(camion.id)} className="p-1 text-red-600 hover:bg-red-50 rounded">
                        <Trash2 size={18} />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {showModal && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h2 className="text-xl font-bold mb-4 dark:text-white">
              {editingCamion ? 'Modifier le camion' : 'Nouveau camion'}
            </h2>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">Immatriculation *</label>
                <input
                  type="text"
                  required
                  value={formData.immatriculation}
                  onChange={(e) => setFormData({ ...formData, immatriculation: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  placeholder="AB-123-CD"
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Marque</label>
                  <input
                    type="text"
                    value={formData.marque}
                    onChange={(e) => setFormData({ ...formData, marque: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Modèle</label>
                  <input
                    type="text"
                    value={formData.modele}
                    onChange={(e) => setFormData({ ...formData, modele: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">Type *</label>
                <select
                  value={formData.type}
                  onChange={(e) => setFormData({ ...formData, type: e.target.value as 'camionnette' | 'camion' | 'semi_remorque' })}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                >
                  <option value="camionnette">Camionnette</option>
                  <option value="camion">Camion</option>
                  <option value="semi_remorque">Semi-remorque</option>
                </select>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Capacité (kg)</label>
                  <input
                    type="number"
                    value={formData.capacite_kg}
                    onChange={(e) => setFormData({ ...formData, capacite_kg: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Capacité (m³)</label>
                  <input
                    type="number"
                    step="0.1"
                    value={formData.capacite_m3}
                    onChange={(e) => setFormData({ ...formData, capacite_m3: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">Date contrôle technique</label>
                <input
                  type="date"
                  value={formData.date_controle_technique}
                  onChange={(e) => setFormData({ ...formData, date_controle_technique: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                />
              </div>
              <div className="flex justify-end gap-3 mt-6">
                <button
                  type="button"
                  onClick={() => setShowModal(false)}
                  className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-white"
                >
                  Annuler
                </button>
                <button type="submit" className="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                  {editingCamion ? 'Modifier' : 'Créer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
