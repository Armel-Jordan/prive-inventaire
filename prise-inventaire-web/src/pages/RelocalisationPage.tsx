import { useEffect, useState } from 'react';
import { ArrowRight, MapPin, Plus, Truck, Filter, Package, Trash2, Layers, X } from 'lucide-react';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';
import PageSkeleton from '@/components/PageSkeleton';
import EmptyState from '@/components/EmptyState';

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

interface BatchItem {
  id: number;
  produit_numero: string;
  produit_nom: string;
  quantite: string;
  unite_mesure: string;
}

export default function RelocalisationPage() {
  const { toasts, toast, dismiss } = useToast();
  const [mouvements, setMouvements] = useState<Mouvement[]>([]);
  const [secteurs, setSecteurs] = useState<Secteur[]>([]);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [modalType, setModalType] = useState<'single' | 'arrivage' | 'transfert' | 'batch'>('single');
  const [filterType, setFilterType] = useState<string>('');
  const [submitting, setSubmitting] = useState(false);

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

  // Batch scan states
  const [batchItems, setBatchItems] = useState<BatchItem[]>([]);
  const [batchType, setBatchType] = useState<'arrivage' | 'transfert' | 'sortie'>('arrivage');
  const [batchSecteurSource, setBatchSecteurSource] = useState('');
  const [batchSecteurDest, setBatchSecteurDest] = useState('');
  const [batchMotif, setBatchMotif] = useState('');
  const [newItem, setNewItem] = useState({ produit_numero: '', produit_nom: '', quantite: '1', unite_mesure: 'unité' });

  useEffect(() => {
    loadData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filterType]);

  async function loadData() {
    setLoading(true);
    try {
      const params = filterType ? `?type=${filterType}` : '';
      const [mouvementsRes, secteursRes] = await Promise.all([
        fetchApi<Mouvement[]>(`/relocalisation${params}`),
        fetchApi<Secteur[]>('/secteurs'),
      ]);
      setMouvements(mouvementsRes);
      setSecteurs(secteursRes);
    } catch {
      toast('Erreur de chargement des données', 'error');
    } finally {
      setLoading(false);
    }
  }

  function openModal(type: 'single' | 'arrivage' | 'transfert' | 'batch') {
    setModalType(type);
    if (type === 'batch') {
      setBatchItems([]);
      setBatchType('arrivage');
      setBatchSecteurSource('');
      setBatchSecteurDest('');
      setBatchMotif('');
      setNewItem({ produit_numero: '', produit_nom: '', quantite: '1', unite_mesure: 'unité' });
    } else {
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
    }
    setShowModal(true);
  }

  function addBatchItem() {
    if (!newItem.produit_numero || !newItem.quantite) return;
    setBatchItems([...batchItems, { ...newItem, id: Date.now() }]);
    setNewItem({ produit_numero: '', produit_nom: '', quantite: '1', unite_mesure: 'unité' });
  }

  function removeBatchItem(id: number) {
    setBatchItems(batchItems.filter(item => item.id !== id));
  }

  async function handleBatchSubmit() {
    if (batchItems.length === 0) {
      toast('Ajoutez au moins un produit', 'error'); return;
    }
    if ((batchType === 'arrivage' || batchType === 'transfert') && !batchSecteurDest) {
      toast('Sélectionnez un secteur destination', 'error'); return;
    }
    if ((batchType === 'transfert' || batchType === 'sortie') && !batchSecteurSource) {
      toast('Sélectionnez un secteur source', 'error'); return;
    }

    setSubmitting(true);
    try {
      // Envoyer chaque produit comme un mouvement séparé
      const promises = batchItems.map(item =>
        fetchApi('/relocalisation', {
          method: 'POST',
          body: JSON.stringify({
            type: batchType,
            produit_numero: item.produit_numero,
            produit_nom: item.produit_nom,
            secteur_source: batchSecteurSource || null,
            secteur_destination: batchSecteurDest || null,
            quantite: parseFloat(item.quantite),
            unite_mesure: item.unite_mesure,
            motif: batchMotif,
            employe: 'Admin Web',
          }),
        })
      );
      await Promise.all(promises);
      setShowModal(false);
      loadData();
    } catch {
      toast('Erreur lors de l\'enregistrement', 'error');
    } finally {
      setSubmitting(false);
    }
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
    } catch {
      toast('Erreur lors de l\'enregistrement', 'error');
    }
  }

  if (loading && mouvements.length === 0) return <PageSkeleton />;

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
            onClick={() => openModal('batch')}
            className="flex items-center gap-2 px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700"
          >
            <Layers size={18} />
            Scan en lot
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
          <EmptyState icon="📦" title="Aucun mouvement enregistré" />
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
                <X size={20} />
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

      {/* Modal Scan en lot */}
      {showModal && modalType === 'batch' && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-2xl mx-4 max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between p-4 border-b">
              <div className="flex items-center gap-2">
                <Layers className="text-orange-600" size={20} />
                <h2 className="text-lg font-semibold">Scan en lot</h2>
              </div>
              <button onClick={() => setShowModal(false)} className="p-1 hover:bg-gray-100 rounded">
                <X size={20} />
              </button>
            </div>

            <div className="p-4 space-y-4">
              {/* Type et secteurs */}
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Type *</label>
                  <select
                    value={batchType}
                    onChange={(e) => setBatchType(e.target.value as typeof batchType)}
                    className="w-full px-3 py-2 border rounded-lg"
                  >
                    <option value="arrivage">Arrivage</option>
                    <option value="transfert">Transfert</option>
                    <option value="sortie">Sortie</option>
                  </select>
                </div>

                {(batchType === 'transfert' || batchType === 'sortie') && (
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Source *</label>
                    <select
                      value={batchSecteurSource}
                      onChange={(e) => setBatchSecteurSource(e.target.value)}
                      className="w-full px-3 py-2 border rounded-lg"
                    >
                      <option value="">Sélectionner...</option>
                      {secteurs.map((s) => (
                        <option key={s.id} value={s.code || s.nom}>{s.nom}</option>
                      ))}
                    </select>
                  </div>
                )}

                {(batchType === 'arrivage' || batchType === 'transfert') && (
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Destination *</label>
                    <select
                      value={batchSecteurDest}
                      onChange={(e) => setBatchSecteurDest(e.target.value)}
                      className="w-full px-3 py-2 border rounded-lg"
                    >
                      <option value="">Sélectionner...</option>
                      {secteurs.map((s) => (
                        <option key={s.id} value={s.code || s.nom}>{s.nom}</option>
                      ))}
                    </select>
                  </div>
                )}
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Motif (optionnel)</label>
                <input
                  type="text"
                  value={batchMotif}
                  onChange={(e) => setBatchMotif(e.target.value)}
                  className="w-full px-3 py-2 border rounded-lg"
                  placeholder="Ex: Livraison fournisseur..."
                />
              </div>

              {/* Ajout de produit */}
              <div className="bg-gray-50 rounded-lg p-4">
                <h3 className="text-sm font-medium text-gray-700 mb-3 flex items-center gap-2">
                  <Package size={16} />
                  Ajouter un produit
                </h3>
                <div className="grid grid-cols-2 md:grid-cols-5 gap-2">
                  <input
                    type="text"
                    value={newItem.produit_numero}
                    onChange={(e) => setNewItem({ ...newItem, produit_numero: e.target.value })}
                    placeholder="N° Produit *"
                    className="px-3 py-2 border rounded-lg text-sm"
                  />
                  <input
                    type="text"
                    value={newItem.produit_nom}
                    onChange={(e) => setNewItem({ ...newItem, produit_nom: e.target.value })}
                    placeholder="Nom (optionnel)"
                    className="px-3 py-2 border rounded-lg text-sm"
                  />
                  <input
                    type="number"
                    step="0.01"
                    min="0.01"
                    value={newItem.quantite}
                    onChange={(e) => setNewItem({ ...newItem, quantite: e.target.value })}
                    placeholder="Qté"
                    className="px-3 py-2 border rounded-lg text-sm"
                  />
                  <select
                    value={newItem.unite_mesure}
                    onChange={(e) => setNewItem({ ...newItem, unite_mesure: e.target.value })}
                    className="px-3 py-2 border rounded-lg text-sm"
                  >
                    <option value="unité">Unité</option>
                    <option value="kg">Kg</option>
                    <option value="carton">Carton</option>
                    <option value="palette">Palette</option>
                  </select>
                  <button
                    type="button"
                    onClick={addBatchItem}
                    className="px-3 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 text-sm flex items-center justify-center gap-1"
                  >
                    <Plus size={16} /> Ajouter
                  </button>
                </div>
              </div>

              {/* Liste des produits */}
              <div>
                <h3 className="text-sm font-medium text-gray-700 mb-2">
                  Produits à traiter ({batchItems.length})
                </h3>
                {batchItems.length === 0 ? (
                  <div className="text-center py-6 text-gray-400 border-2 border-dashed rounded-lg">
                    Aucun produit ajouté
                  </div>
                ) : (
                  <div className="border rounded-lg divide-y max-h-48 overflow-y-auto">
                    {batchItems.map((item) => (
                      <div key={item.id} className="flex items-center justify-between p-3">
                        <div className="flex items-center gap-4">
                          <span className="font-mono font-medium">{item.produit_numero}</span>
                          {item.produit_nom && <span className="text-gray-500 text-sm">{item.produit_nom}</span>}
                        </div>
                        <div className="flex items-center gap-3">
                          <span className="text-sm font-medium">{item.quantite} {item.unite_mesure}</span>
                          <button
                            onClick={() => removeBatchItem(item.id)}
                            className="p-1 text-red-500 hover:bg-red-50 rounded"
                          >
                            <Trash2 size={16} />
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              {/* Actions */}
              <div className="flex gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => setShowModal(false)}
                  className="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50"
                >
                  Annuler
                </button>
                <button
                  type="button"
                  onClick={handleBatchSubmit}
                  disabled={submitting || batchItems.length === 0}
                  className="flex-1 px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 disabled:opacity-50 flex items-center justify-center gap-2"
                >
                  {submitting ? 'Enregistrement...' : `Enregistrer ${batchItems.length} produit(s)`}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}
