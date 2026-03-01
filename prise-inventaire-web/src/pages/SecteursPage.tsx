import { useEffect, useState } from 'react';
import { Plus, Pencil, Trash2 } from 'lucide-react';
import { getSecteurs, createSecteur, updateSecteur, deleteSecteur } from '@/services/api';
import type { Secteur } from '@/types';

export default function SecteursPage() {
  const [secteurs, setSecteurs] = useState<Secteur[]>([]);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [editingSecteur, setEditingSecteur] = useState<Secteur | null>(null);
  const [form, setForm] = useState({ code: '', nom: '', description: '' });

  async function loadData() {
    try {
      const data = await getSecteurs();
      setSecteurs(data);
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadData();
  }, []);

  function openCreate() {
    setEditingSecteur(null);
    setForm({ code: '', nom: '', description: '' });
    setShowModal(true);
  }

  function openEdit(secteur: Secteur) {
    setEditingSecteur(secteur);
    setForm({ code: secteur.code, nom: secteur.nom, description: secteur.description || '' });
    setShowModal(true);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    try {
      if (editingSecteur) {
        await updateSecteur(editingSecteur.id!, form);
      } else {
        await createSecteur(form);
      }
      setShowModal(false);
      loadData();
    } catch (error) {
      console.error('Erreur sauvegarde:', error);
    }
  }

  async function handleDelete(id: number) {
    if (!confirm('Supprimer ce secteur ?')) return;
    try {
      await deleteSecteur(id);
      loadData();
    } catch (error) {
      console.error('Erreur suppression:', error);
    }
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold text-gray-800">Secteurs</h1>
        <button
          onClick={openCreate}
          className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
        >
          <Plus size={20} />
          Ajouter
        </button>
      </div>

      {/* Table */}
      <div className="bg-white rounded-xl shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-6 text-gray-500">Chargement...</div>
        ) : secteurs.length === 0 ? (
          <div className="p-6 text-gray-500">Aucun secteur</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 border-b">
                <tr>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Code</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Nom</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Description</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {secteurs.map((secteur) => (
                  <tr key={secteur.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3">
                      <span className="bg-purple-100 text-purple-700 px-2 py-1 rounded text-sm font-medium">
                        {secteur.code}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-sm font-medium">{secteur.nom}</td>
                    <td className="px-4 py-3 text-sm text-gray-500">{secteur.description || '-'}</td>
                    <td className="px-4 py-3">
                      <div className="flex gap-2">
                        <button
                          onClick={() => openEdit(secteur)}
                          className="p-1.5 text-blue-600 hover:bg-blue-50 rounded"
                          title="Modifier"
                        >
                          <Pencil size={16} />
                        </button>
                        <button
                          onClick={() => handleDelete(secteur.id!)}
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

      {/* Modal */}
      {showModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl p-6 w-full max-w-md mx-4">
            <h2 className="text-lg font-bold mb-4">
              {editingSecteur ? 'Modifier le secteur' : 'Nouveau secteur'}
            </h2>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Code</label>
                <input
                  type="text"
                  value={form.code}
                  onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })}
                  className="w-full border rounded-lg px-3 py-2"
                  placeholder="Ex: A1, B2..."
                  pattern="[A-Za-z]\d{1,2}"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Nom</label>
                <input
                  type="text"
                  value={form.nom}
                  onChange={(e) => setForm({ ...form, nom: e.target.value })}
                  className="w-full border rounded-lg px-3 py-2"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea
                  value={form.description}
                  onChange={(e) => setForm({ ...form, description: e.target.value })}
                  className="w-full border rounded-lg px-3 py-2"
                  rows={3}
                />
              </div>
              <div className="flex gap-3 justify-end pt-2">
                <button
                  type="button"
                  onClick={() => setShowModal(false)}
                  className="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg"
                >
                  Annuler
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                >
                  {editingSecteur ? 'Enregistrer' : 'Créer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
