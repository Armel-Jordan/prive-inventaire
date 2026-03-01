import { useEffect, useState } from 'react';
import { ArrowRight, Package, MapPin, Plus, Truck, History, Filter } from 'lucide-react';

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const STORAGE_KEY = 'prise_auth';

function getAuthHeaders(): Record<string, string> {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  };
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

async function fetchApi<T>(endpoint: string, options?: RequestInit): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${endpoint}`, {
    ...options,
    headers: { ...getAuthHeaders(), ...options?.headers },
  });
  if (!response.ok) throw new Error(`API Error: ${response.status}`);
  return response.json();
}

interface Mouvement {
  id: number;
  type: 'arrivage' | 'transfert' | 'sortie' | 'ajustement';
  produit_numero: string;
  produit_nom: string | null;
  secteur_source: string | null;
  secteur_destination: string | null;
  quantite: number;
  unite_mesure: string | null;
  motif: string | null;
  employe: string;
  date_mouvement: string;
}

interface Stats {
  total: number;
  today: number;
  this_month: number;
  by_type: Record<string, number>;
}

interface Secteur {
  id: number;
  nom: string;
  code: string;
}

const typeLabels: Record<string, { label: string; color: string; icon: string }> = {
  arrivage: { label: 'Arrivage', color: 'bg-green-100 text-green-700', icon: '📦' },
  transfert: { label: 'Transfert', color: 'bg-blue-100 text-blue-700', icon: '🔄' },
  sortie: { label: 'Sortie', color: 'bg-red-100 text-red-700', icon: '📤' },
  ajustement: { label: 'Ajustement', color: 'bg-yellow-100 text-yellow-700', icon: '✏️' },
};

export default function RelocalisationPage() {
  const [mouvements, setMouvements] = useState<Mouvement[]>([]);
  const [stats, setStats] = useState<Stats | null>(null);
  const [secteurs, setSecteurs] = useState<Secteur[]>([]);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [modalType, setModalType] = useState<'single' | 'arrivage' | 'transfert'>('single');
  const [filterType, setFilterType] = useState<string>('');

  // Form states
  const [form, setForm] = useState({
    type: 'arrivage' as 'arrivage' | 'transfert' | 'sortie' | 'ajustement',
    produit_numero: '',
    produit_nom: '',
    secteur_source: '',
    secteur_destination: '',
    quantite: '',
    unite_mesure: 'unité',
    motif: '',
  });

  useEffect(() => {
    loadData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filterType]);

  async function loadData() {
    setLoading(true);
    try {
      const params = filterType ? `?type=${filterType}` : '';
      const [mouvementsRes, statsRes, secteursRes] = await Promise.all([
        fetchApi<Mouvement[]>(`/relocalisation${params}`),
        fetchApi<Stats>('/relocalisation/stats'),
        fetchApi<Secteur[]>('/secteurs'),
      ]);
      setMouvements(mouvementsRes);
      setStats(statsRes);
      setSecteurs(secteursRes);
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }

  function openModal(type: 'single' | 'arrivage' | 'transfert') {
    setModalType(type);
    setForm({
      type: type === 'arrivage' ? 'arrivage' : type === 'transfert' ? 'transfert' : 'arrivage',
      produit_numero: '',
      produit_nom: '',
      secteur_source: '',
      secteur_destination: '',
      quantite: '',
      unite_mesure: 'unité',
      motif: '',
    });
    setShowModal(true);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    try {
      await fetchApi('/relocalisation', {
        method: 'POST',
        body: JSON.stringify({
          ...form,
          quantite: parseFloat(form.quantite),
          employe: 'Admin Web',
        }),
      });
      setShowModal(false);
      loadData();
    } catch (error) {
      console.error('Erreur:', error);
      alert('Erreur lors de l\'enregistrement');
    }
  }

  if (loading && mouvements.length === 0) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="text-gray-500">Chargement...</div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-800">Relocalisation</h1>
          <p className="text-gray-500">Gérez les arrivages, transferts et mouvements de stock</p>
        </div>
        <div className="flex gap-2">
          <button
            onClick={() => openModal('arrivage')}
            className="flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
          >
            <Truck size={18} />
            Arrivage
          </button>
          <button
            onClick={() => openModal('transfert')}
            className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
          >
            <ArrowRight size={18} />
            Transfert
          </button>
          <button
            onClick={() => openModal('single')}
            className="flex items-center gap-2 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700"
          >
            <Plus size={18} />
            Mouvement
          </button>
        </div>
      </div>

      {/* Stats */}
      {stats && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div className="bg-white rounded-xl shadow-sm p-4">
            <div className="flex items-center gap-3">
              <div className="p-2 bg-purple-100 rounded-lg">
                <History className="text-purple-600" size={20} />
              </div>
              <div>
                <p className="text-2xl font-bold">{stats.total}</p>
                <p className="text-sm text-gray-500">Total mouvements</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-4">
            <div className="flex items-center gap-3">
              <div className="p-2 bg-green-100 rounded-lg">
                <Truck className="text-green-600" size={20} />
              </div>
              <div>
                <p className="text-2xl font-bold">{stats.by_type?.arrivage || 0}</p>
                <p className="text-sm text-gray-500">Arrivages</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-4">
            <div className="flex items-center gap-3">
              <div className="p-2 bg-blue-100 rounded-lg">
                <ArrowRight className="text-blue-600" size={20} />
              </div>
              <div>
                <p className="text-2xl font-bold">{stats.by_type?.transfert || 0}</p>
                <p className="text-sm text-gray-500">Transferts</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-4">
            <div className="flex items-center gap-3">
              <div className="p-2 bg-orange-100 rounded-lg">
                <Package className="text-orange-600" size={20} />
              </div>
              <div>
                <p className="text-2xl font-bold">{stats.today}</p>
                <p className="text-sm text-gray-500">Aujourd'hui</p>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Filters & List */}
      <div className="bg-white rounded-xl shadow-sm">
        <div className="flex items-center justify-between p-4 border-b">
          <h2 className="text-lg font-semibold">Historique des mouvements</h2>
          <div className="flex items-center gap-2">
            <Filter size={18} className="text-gray-400" />
            <select
              value={filterType}
              onChange={(e) => setFilterType(e.target.value)}
              className="border rounded-lg px-3 py-1.5 text-sm"
            >
              <option value="">Tous les types</option>
              <option value="arrivage">Arrivages</option>
              <option value="transfert">Transferts</option>
              <option value="sortie">Sorties</option>
              <option value="ajustement">Ajustements</option>
            </select>
          </div>
        </div>

        {mouvements.length === 0 ? (
          <div className="p-8 text-center text-gray-500">
            Aucun mouvement enregistré
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 border-b">
                <tr>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Type</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Produit</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Mouvement</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Quantité</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Employé</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Date</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {mouvements.map((m) => (
                  <tr key={m.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3">
                      <span className={`px-2 py-1 rounded text-xs font-medium ${typeLabels[m.type]?.color}`}>
                        {typeLabels[m.type]?.icon} {typeLabels[m.type]?.label}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <div>
                        <p className="font-medium">{m.produit_numero}</p>
                        {m.produit_nom && <p className="text-sm text-gray-500">{m.produit_nom}</p>}
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-2 text-sm">
                        {m.secteur_source && (
                          <span className="flex items-center gap-1">
                            <MapPin size={14} className="text-red-500" />
                            {m.secteur_source}
                          </span>
                        )}
                        {m.secteur_source && m.secteur_destination && (
                          <ArrowRight size={14} className="text-gray-400" />
                        )}
                        {m.secteur_destination && (
                          <span className="flex items-center gap-1">
                            <MapPin size={14} className="text-green-500" />
                            {m.secteur_destination}
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="px-4 py-3 font-medium">
                      {m.quantite} {m.unite_mesure}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">{m.employe}</td>
                    <td className="px-4 py-3 text-sm text-gray-500">
                      {new Date(m.date_mouvement).toLocaleString('fr-FR')}
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
          <div className="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between p-4 border-b">
              <h2 className="text-lg font-semibold">
                {modalType === 'arrivage' ? 'Nouvel arrivage' : 
                 modalType === 'transfert' ? 'Nouveau transfert' : 
                 'Nouveau mouvement'}
              </h2>
              <button onClick={() => setShowModal(false)} className="p-1 hover:bg-gray-100 rounded">
                ✕
              </button>
            </div>
            <form onSubmit={handleSubmit} className="p-4 space-y-4">
              {modalType === 'single' && (
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Type *</label>
                  <select
                    value={form.type}
                    onChange={(e) => setForm({ ...form, type: e.target.value as typeof form.type })}
                    className="w-full px-3 py-2 border rounded-lg"
                    required
                  >
                    <option value="arrivage">Arrivage</option>
                    <option value="transfert">Transfert</option>
                    <option value="sortie">Sortie</option>
                    <option value="ajustement">Ajustement</option>
                  </select>
                </div>
              )}

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">N° Produit *</label>
                  <input
                    type="text"
                    value={form.produit_numero}
                    onChange={(e) => setForm({ ...form, produit_numero: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                    placeholder="Ex: PRD001"
                    required
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Nom produit</label>
                  <input
                    type="text"
                    value={form.produit_nom}
                    onChange={(e) => setForm({ ...form, produit_nom: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                    placeholder="Optionnel"
                  />
                </div>
              </div>

              {(form.type === 'transfert' || form.type === 'sortie') && (
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Secteur source *</label>
                  <select
                    value={form.secteur_source}
                    onChange={(e) => setForm({ ...form, secteur_source: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                    required
                  >
                    <option value="">Sélectionner...</option>
                    {secteurs.map((s) => (
                      <option key={s.id} value={s.code || s.nom}>{s.nom}</option>
                    ))}
                  </select>
                </div>
              )}

              {(form.type === 'arrivage' || form.type === 'transfert') && (
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Secteur destination *</label>
                  <select
                    value={form.secteur_destination}
                    onChange={(e) => setForm({ ...form, secteur_destination: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                    required
                  >
                    <option value="">Sélectionner...</option>
                    {secteurs.map((s) => (
                      <option key={s.id} value={s.code || s.nom}>{s.nom}</option>
                    ))}
                  </select>
                </div>
              )}

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Quantité *</label>
                  <input
                    type="number"
                    step="0.01"
                    min="0.01"
                    value={form.quantite}
                    onChange={(e) => setForm({ ...form, quantite: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                    required
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Unité</label>
                  <select
                    value={form.unite_mesure}
                    onChange={(e) => setForm({ ...form, unite_mesure: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                  >
                    <option value="unité">Unité</option>
                    <option value="kg">Kilogramme</option>
                    <option value="g">Gramme</option>
                    <option value="L">Litre</option>
                    <option value="mL">Millilitre</option>
                    <option value="m">Mètre</option>
                    <option value="carton">Carton</option>
                    <option value="palette">Palette</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Motif</label>
                <input
                  type="text"
                  value={form.motif}
                  onChange={(e) => setForm({ ...form, motif: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg"
                  placeholder="Ex: Livraison fournisseur, Restructuration..."
                />
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
                  className="flex-1 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700"
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
