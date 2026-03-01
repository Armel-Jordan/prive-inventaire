import { useEffect, useState } from 'react';
import { Search, Plus, X, Edit2, Trash2 } from 'lucide-react';
import { getProduits, createProduit, updateProduit, deleteProduit } from '@/services/api';
import type { Produit } from '@/types';

interface ProduitForm {
  numero: string;
  description: string;
  mesure: string;
  type: string;
}

const emptyForm: ProduitForm = { numero: '', description: '', mesure: 'UN', type: '' };

export default function ProduitsPage() {
  const [produits, setProduits] = useState<Produit[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState<ProduitForm>(emptyForm);

  async function loadData() {
    try {
      const data = await getProduits();
      setProduits(data);
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadData();
  }, []);

  const filtered = produits.filter(p => 
    p.numero.toLowerCase().includes(search.toLowerCase()) ||
    p.description.toLowerCase().includes(search.toLowerCase())
  );

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
    });
    setEditingId(produit.id!);
    setShowModal(true);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    try {
      if (editingId) {
        await updateProduit(editingId, form);
      } else {
        await createProduit(form);
      }
      setShowModal(false);
      loadData();
    } catch (error) {
      console.error('Erreur:', error);
      alert('Erreur lors de la sauvegarde');
    }
  }

  async function handleDelete(id: number) {
    if (!confirm('Voulez-vous vraiment supprimer ce produit ?')) return;
    try {
      await deleteProduit(id);
      loadData();
    } catch (error) {
      console.error('Erreur:', error);
      alert('Erreur lors de la suppression');
    }
  }

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
        <h1 className="text-2xl font-bold text-gray-800">Produits</h1>
        <button
          onClick={openCreate}
          className="flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors"
        >
          <Plus size={20} />
          Ajouter un produit
        </button>
      </div>

      {/* Recherche */}
      <div className="bg-white rounded-xl shadow-sm p-4 mb-6">
        <div className="relative">
          <Search size={20} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input
            type="text"
            placeholder="Rechercher par numéro ou description..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full pl-10 pr-4 py-2 border rounded-lg"
          />
        </div>
      </div>

      {/* Table */}
      <div className="bg-white rounded-xl shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-6 text-gray-500">Chargement...</div>
        ) : filtered.length === 0 ? (
          <div className="p-6 text-gray-500">Aucun produit trouvé</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 border-b">
                <tr>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Numéro</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Description</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Unité</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Type</th>
                  <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {filtered.map((produit) => (
                  <tr key={produit.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 text-sm font-mono font-medium">{produit.numero}</td>
                    <td className="px-4 py-3 text-sm">{produit.description}</td>
                    <td className="px-4 py-3 text-sm">{produit.mesure}</td>
                    <td className="px-4 py-3 text-sm">
                      {produit.type && (
                        <span className="bg-green-100 text-green-700 px-2 py-1 rounded text-xs font-medium">
                          {produit.type}
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <button
                        onClick={() => openEdit(produit)}
                        className="p-1 text-blue-600 hover:bg-blue-50 rounded mr-2"
                      >
                        <Edit2 size={16} />
                      </button>
                      <button
                        onClick={() => handleDelete(produit.id!)}
                        className="p-1 text-red-600 hover:bg-red-50 rounded"
                      >
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

      {/* Modal */}
      {showModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
            <div className="flex items-center justify-between p-4 border-b">
              <h2 className="text-lg font-semibold">
                {editingId ? 'Modifier le produit' : 'Nouveau produit'}
              </h2>
              <button onClick={() => setShowModal(false)} className="p-1 hover:bg-gray-100 rounded">
                <X size={20} />
              </button>
            </div>
            <form onSubmit={handleSubmit} className="p-4 space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Numéro *</label>
                <input
                  type="text"
                  value={form.numero}
                  onChange={(e) => setForm({ ...form, numero: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg"
                  required
                  disabled={!!editingId}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Description *</label>
                <input
                  type="text"
                  value={form.description}
                  onChange={(e) => setForm({ ...form, description: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg"
                  required
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Unité de mesure</label>
                  <select
                    value={form.mesure}
                    onChange={(e) => setForm({ ...form, mesure: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                  >
                    <option value="UN">UN (Unité)</option>
                    <option value="KG">KG (Kilogramme)</option>
                    <option value="L">L (Litre)</option>
                    <option value="M">M (Mètre)</option>
                    <option value="M2">M² (Mètre carré)</option>
                    <option value="M3">M³ (Mètre cube)</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Type</label>
                  <input
                    type="text"
                    value={form.type}
                    onChange={(e) => setForm({ ...form, type: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                    placeholder="ex: PIECE, LIQUIDE"
                  />
                </div>
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
                  className="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
                >
                  {editingId ? 'Modifier' : 'Créer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
