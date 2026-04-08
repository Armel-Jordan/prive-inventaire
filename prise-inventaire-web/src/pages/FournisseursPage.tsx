import { useEffect, useState } from 'react';
import { Search, Plus, X, Edit2, Trash2, Building2, Phone, Mail } from 'lucide-react';
import { getFournisseurs, createFournisseur, updateFournisseur, deleteFournisseur, getConfiguration } from '@/services/api';
import type { Fournisseur } from '@/services/api';

interface ConfigNumero {
  auto_increment: boolean;
  prefixe: string;
  suffixe: string;
  longueur: number;
  separateur?: string;
  prochain_numero: number;
}

interface FournisseurForm {
  code: string;
  raison_sociale: string;
  adresse: string;
  telephone: string;
  email: string;
  contact_nom: string;
  contact_telephone: string;
  conditions_paiement: string;
  actif: boolean;
}

const emptyForm: FournisseurForm = {
  code: '',
  raison_sociale: '',
  adresse: '',
  telephone: '',
  email: '',
  contact_nom: '',
  contact_telephone: '',
  conditions_paiement: '',
  actif: true,
};

export default function FournisseursPage() {
  const [fournisseurs, setFournisseurs] = useState<Fournisseur[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState<FournisseurForm>(emptyForm);
  const [configNumero, setConfigNumero] = useState<ConfigNumero | null>(null);

  async function loadData() {
    try {
      const response = await getFournisseurs({ search: search || undefined });
      setFournisseurs(response.data || []);
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }

  async function loadConfig() {
    try {
      const config = await getConfiguration('fournisseur');
      setConfigNumero(config);
    } catch (error) {
      console.error('Erreur chargement config:', error);
    }
  }

  useEffect(() => {
    loadData();
    loadConfig();
  }, []);

  useEffect(() => {
    const timer = setTimeout(() => {
      loadData();
    }, 300);
    return () => clearTimeout(timer);
  }, [search]);

  function openCreate() {
    setForm(emptyForm);
    setEditingId(null);
    setShowModal(true);
  }

  function openEdit(fournisseur: Fournisseur) {
    setForm({
      code: fournisseur.code,
      raison_sociale: fournisseur.raison_sociale,
      adresse: fournisseur.adresse || '',
      telephone: fournisseur.telephone || '',
      email: fournisseur.email || '',
      contact_nom: fournisseur.contact_nom || '',
      contact_telephone: fournisseur.contact_telephone || '',
      conditions_paiement: fournisseur.conditions_paiement || '',
      actif: fournisseur.actif,
    });
    setEditingId(fournisseur.id);
    setShowModal(true);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    try {
      if (editingId) {
        await updateFournisseur(editingId, form);
      } else {
        await createFournisseur(form);
      }
      setShowModal(false);
      loadData();
    } catch (error) {
      console.error('Erreur:', error);
      alert('Erreur lors de la sauvegarde');
    }
  }

  async function handleDelete(id: number) {
    if (!confirm('Voulez-vous vraiment supprimer ce fournisseur ?')) return;
    try {
      await deleteFournisseur(id);
      loadData();
    } catch (error) {
      console.error('Erreur:', error);
      alert('Erreur lors de la suppression. Ce fournisseur a peut-être des commandes associées.');
    }
  }

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
        <h1 className="text-2xl font-bold text-gray-800">Fournisseurs</h1>
        <button
          onClick={openCreate}
          className="flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors"
        >
          <Plus size={20} />
          Ajouter
        </button>
      </div>

      <div className="bg-white rounded-xl shadow-sm p-4 mb-6">
        <div className="relative">
          <Search size={20} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input
            type="text"
            placeholder="Rechercher par code, nom ou email..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full pl-10 pr-4 py-2 border rounded-lg"
          />
        </div>
      </div>

      <div className="bg-white rounded-xl shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-6 text-gray-500">Chargement...</div>
        ) : fournisseurs.length === 0 ? (
          <div className="p-6 text-gray-500">Aucun fournisseur trouvé</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 border-b">
                <tr>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Code</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Raison sociale</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Contact</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Téléphone</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Statut</th>
                  <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {fournisseurs.map((fournisseur) => (
                  <tr key={fournisseur.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 text-sm font-mono font-medium">{fournisseur.code}</td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        <Building2 size={16} className="text-gray-400" />
                        <span className="text-sm font-medium">{fournisseur.raison_sociale}</span>
                      </div>
                      {fournisseur.email && (
                        <div className="flex items-center gap-2 mt-1">
                          <Mail size={14} className="text-gray-400" />
                          <span className="text-xs text-gray-500">{fournisseur.email}</span>
                        </div>
                      )}
                    </td>
                    <td className="px-4 py-3 text-sm">{fournisseur.contact_nom || '-'}</td>
                    <td className="px-4 py-3">
                      {fournisseur.telephone && (
                        <div className="flex items-center gap-1 text-sm">
                          <Phone size={14} className="text-gray-400" />
                          {fournisseur.telephone}
                        </div>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      <span className={`px-2 py-1 rounded text-xs font-medium ${
                        fournisseur.actif 
                          ? 'bg-green-100 text-green-700' 
                          : 'bg-gray-100 text-gray-600'
                      }`}>
                        {fournisseur.actif ? 'Actif' : 'Inactif'}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-right">
                      <button
                        onClick={() => openEdit(fournisseur)}
                        className="p-1 text-blue-600 hover:bg-blue-50 rounded mr-2"
                      >
                        <Edit2 size={16} />
                      </button>
                      <button
                        onClick={() => handleDelete(fournisseur.id)}
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

      {showModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-2xl mx-4 max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between p-4 border-b sticky top-0 bg-white">
              <h2 className="text-lg font-semibold">
                {editingId ? 'Modifier le fournisseur' : 'Nouveau fournisseur'}
              </h2>
              <button onClick={() => setShowModal(false)} className="p-1 hover:bg-gray-100 rounded">
                <X size={20} />
              </button>
            </div>
            <form onSubmit={handleSubmit} className="p-4 space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Code {!editingId && configNumero?.auto_increment ? '(auto-généré)' : '*'}
                  </label>
                  <input
                    type="text"
                    value={form.code}
                    onChange={(e) => setForm({ ...form, code: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg disabled:bg-gray-100"
                    required={!!editingId || !configNumero?.auto_increment}
                    disabled={!!editingId}
                    placeholder={!editingId && configNumero?.auto_increment ? 'Généré automatiquement' : ''}
                  />
                  {!editingId && configNumero?.auto_increment && (
                    <p className="text-xs text-gray-500 mt-1">
                      Format: {configNumero.prefixe}{configNumero.separateur || ''}{String(configNumero.prochain_numero).padStart(configNumero.longueur, '0')}{configNumero.suffixe ? (configNumero.separateur || '') + configNumero.suffixe : ''}
                    </p>
                  )}
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Raison sociale *</label>
                  <input
                    type="text"
                    value={form.raison_sociale}
                    onChange={(e) => setForm({ ...form, raison_sociale: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                    required
                  />
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Adresse</label>
                <textarea
                  value={form.adresse}
                  onChange={(e) => setForm({ ...form, adresse: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg"
                  rows={2}
                />
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                  <input
                    type="text"
                    value={form.telephone}
                    onChange={(e) => setForm({ ...form, telephone: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Email</label>
                  <input
                    type="email"
                    value={form.email}
                    onChange={(e) => setForm({ ...form, email: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Nom du contact</label>
                  <input
                    type="text"
                    value={form.contact_nom}
                    onChange={(e) => setForm({ ...form, contact_nom: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Téléphone du contact</label>
                  <input
                    type="text"
                    value={form.contact_telephone}
                    onChange={(e) => setForm({ ...form, contact_telephone: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Conditions de paiement</label>
                  <input
                    type="text"
                    value={form.conditions_paiement}
                    onChange={(e) => setForm({ ...form, conditions_paiement: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                    placeholder="ex: 30 jours"
                  />
                </div>
                <div className="flex items-center pt-6">
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={form.actif}
                      onChange={(e) => setForm({ ...form, actif: e.target.checked })}
                      className="w-4 h-4 rounded border-gray-300"
                    />
                    <span className="text-sm font-medium text-gray-700">Fournisseur actif</span>
                  </label>
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
