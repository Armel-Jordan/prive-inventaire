import { useEffect, useState } from 'react';
import { Calendar, Clock, Plus, Play, X, Trash2, Edit, CheckCircle, XCircle, AlertCircle } from 'lucide-react';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';
import PageSkeleton from '@/components/PageSkeleton';

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const STORAGE_KEY = 'prise_auth';

function getAuthHeaders(): Record<string, string> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
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

interface TransfertPlanifie {
  id: number;
  type: 'arrivage' | 'transfert' | 'sortie';
  produit_numero: string;
  produit_nom: string | null;
  secteur_source: string | null;
  secteur_destination: string | null;
  quantite: number;
  unite_mesure: string | null;
  motif: string | null;
  employe: string;
  date_planifiee: string;
  statut: 'planifie' | 'execute' | 'annule';
  cree_par: string | null;
  execute_le: string | null;
  execute_par: string | null;
  notes: string | null;
}

interface Secteur {
  id: number;
  nom: string;
  code: string;
}

interface Stats {
  planifies: number;
  executes_ce_mois: number;
  annules_ce_mois: number;
  a_venir_24h: number;
}

const typeLabels: Record<string, { label: string; color: string }> = {
  arrivage: { label: 'Arrivage', color: 'bg-green-100 text-green-700' },
  transfert: { label: 'Transfert', color: 'bg-blue-100 text-blue-700' },
  sortie: { label: 'Sortie', color: 'bg-red-100 text-red-700' },
};

const statutLabels: Record<string, { label: string; color: string; icon: typeof CheckCircle }> = {
  planifie: { label: 'Planifié', color: 'bg-yellow-100 text-yellow-700', icon: Clock },
  execute: { label: 'Exécuté', color: 'bg-green-100 text-green-700', icon: CheckCircle },
  annule: { label: 'Annulé', color: 'bg-gray-100 text-gray-500', icon: XCircle },
};

export default function PlanificationPage() {
  const { toasts, toast, dismiss } = useToast();
  const [transferts, setTransferts] = useState<TransfertPlanifie[]>([]);
  const [secteurs, setSecteurs] = useState<Secteur[]>([]);
  const [stats, setStats] = useState<Stats | null>(null);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [filterStatut, setFilterStatut] = useState('planifie');
  const [editingId, setEditingId] = useState<number | null>(null);

  const [form, setForm] = useState({
    type: 'transfert' as 'arrivage' | 'transfert' | 'sortie',
    produit_numero: '',
    produit_nom: '',
    secteur_source: '',
    secteur_destination: '',
    quantite: '',
    unite_mesure: 'unité',
    motif: '',
    employe: '',
    date_planifiee: '',
    notes: '',
  });

  useEffect(() => {
    loadData();
  }, [filterStatut]);

  async function loadData() {
    setLoading(true);
    try {
      const params = filterStatut ? `?statut=${filterStatut}` : '';
      const [transfertsRes, secteursRes, statsRes] = await Promise.all([
        fetch(`${API_BASE_URL}/transferts-planifies${params}`, { headers: getAuthHeaders() }),
        fetch(`${API_BASE_URL}/secteurs`, { headers: getAuthHeaders() }),
        fetch(`${API_BASE_URL}/transferts-planifies/stats`, { headers: getAuthHeaders() }),
      ]);

      if (transfertsRes.ok) setTransferts(await transfertsRes.json());
      if (secteursRes.ok) setSecteurs(await secteursRes.json());
      if (statsRes.ok) setStats(await statsRes.json());
    } catch (error) {
      toast('Erreur de chargement des données', 'error');
    } finally {
      setLoading(false);
    }
  }

  function openModal(transfert?: TransfertPlanifie) {
    if (transfert) {
      setEditingId(transfert.id);
      setForm({
        type: transfert.type,
        produit_numero: transfert.produit_numero,
        produit_nom: transfert.produit_nom || '',
        secteur_source: transfert.secteur_source || '',
        secteur_destination: transfert.secteur_destination || '',
        quantite: String(transfert.quantite),
        unite_mesure: transfert.unite_mesure || 'unité',
        motif: transfert.motif || '',
        employe: transfert.employe,
        date_planifiee: transfert.date_planifiee.slice(0, 16),
        notes: transfert.notes || '',
      });
    } else {
      setEditingId(null);
      const tomorrow = new Date();
      tomorrow.setDate(tomorrow.getDate() + 1);
      tomorrow.setHours(9, 0, 0, 0);
      setForm({
        type: 'transfert',
        produit_numero: '',
        produit_nom: '',
        secteur_source: '',
        secteur_destination: '',
        quantite: '',
        unite_mesure: 'unité',
        motif: '',
        employe: '',
        date_planifiee: tomorrow.toISOString().slice(0, 16),
        notes: '',
      });
    }
    setShowModal(true);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    try {
      const url = editingId 
        ? `${API_BASE_URL}/transferts-planifies/${editingId}`
        : `${API_BASE_URL}/transferts-planifies`;
      
      const response = await fetch(url, {
        method: editingId ? 'PUT' : 'POST',
        headers: getAuthHeaders(),
        body: JSON.stringify({
          ...form,
          quantite: parseFloat(form.quantite),
        }),
      });

      if (response.ok) {
        setShowModal(false);
        loadData();
      } else {
        const error = await response.json();
        toast(error.message || 'Erreur lors de l\'enregistrement', 'error');
      }
    } catch (error) {
      toast('Erreur lors de l\'enregistrement', 'error');
    }
  }

  async function executeTransfert(id: number) {
    if (!confirm('Exécuter ce transfert maintenant ?')) return;
    try {
      const response = await fetch(`${API_BASE_URL}/transferts-planifies/${id}/execute`, {
        method: 'POST',
        headers: getAuthHeaders(),
      });
      if (response.ok) {
        loadData();
      } else {
        const error = await response.json();
        toast(error instanceof Error ? error.message : 'Une erreur est survenue', 'error');
      }
    } catch (error) {
      toast('Une erreur est survenue', 'error');
    }
  }

  async function cancelTransfert(id: number) {
    if (!confirm('Annuler ce transfert ?')) return;
    try {
      const response = await fetch(`${API_BASE_URL}/transferts-planifies/${id}/cancel`, {
        method: 'POST',
        headers: getAuthHeaders(),
      });
      if (response.ok) {
        loadData();
      }
    } catch (error) {
      toast('Une erreur est survenue', 'error');
    }
  }

  async function deleteTransfert(id: number) {
    if (!confirm('Supprimer ce transfert ?')) return;
    try {
      const response = await fetch(`${API_BASE_URL}/transferts-planifies/${id}`, {
        method: 'DELETE',
        headers: getAuthHeaders(),
      });
      if (response.ok) {
        loadData();
      }
    } catch (error) {
      toast('Une erreur est survenue', 'error');
    }
  }

  if (loading && transferts.length === 0) return <PageSkeleton />;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-800">Planification des Transferts</h1>
          <p className="text-gray-500">Programmez vos mouvements de stock à l'avance</p>
        </div>
        <button
          onClick={() => openModal()}
          className="flex items-center gap-2 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700"
        >
          <Plus size={18} />
          Planifier un transfert
        </button>
      </div>

      {/* Stats */}
      {stats && (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div className="bg-white rounded-xl shadow-sm p-5">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-yellow-100 rounded-lg">
                <Clock className="text-yellow-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-gray-800">{stats.planifies}</p>
                <p className="text-sm text-gray-500">Planifiés</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-5">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-orange-100 rounded-lg">
                <AlertCircle className="text-orange-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-orange-600">{stats.a_venir_24h}</p>
                <p className="text-sm text-gray-500">Dans les 24h</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-5">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-green-100 rounded-lg">
                <CheckCircle className="text-green-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-green-600">{stats.executes_ce_mois}</p>
                <p className="text-sm text-gray-500">Exécutés (mois)</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-5">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-gray-100 rounded-lg">
                <XCircle className="text-gray-500" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-gray-500">{stats.annules_ce_mois}</p>
                <p className="text-sm text-gray-500">Annulés (mois)</p>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Filtres */}
      <div className="bg-white rounded-xl shadow-sm p-4">
        <div className="flex items-center gap-4">
          <span className="text-sm font-medium text-gray-700">Statut:</span>
          <div className="flex gap-2">
            {['', 'planifie', 'execute', 'annule'].map((statut) => (
              <button
                key={statut}
                onClick={() => setFilterStatut(statut)}
                className={`px-3 py-1.5 rounded-lg text-sm ${
                  filterStatut === statut
                    ? 'bg-purple-600 text-white'
                    : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                }`}
              >
                {statut === '' ? 'Tous' : statutLabels[statut]?.label || statut}
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* Liste */}
      <div className="bg-white rounded-xl shadow-sm overflow-hidden">
        {transferts.length === 0 ? (
          <div className="p-8 text-center text-gray-500">
            <Calendar className="mx-auto text-gray-300 mb-4" size={48} />
            <p>Aucun transfert planifié</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 border-b">
                <tr>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Date</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Type</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Produit</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Mouvement</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Quantité</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Statut</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {transferts.map((t) => {
                  const statutInfo = statutLabels[t.statut];
                  const StatusIcon = statutInfo?.icon || Clock;
                  const isPast = new Date(t.date_planifiee) < new Date() && t.statut === 'planifie';

                  return (
                    <tr key={t.id} className={`hover:bg-gray-50 ${isPast ? 'bg-red-50' : ''}`}>
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-2">
                          <Calendar size={14} className="text-gray-400" />
                          <span className={`text-sm ${isPast ? 'text-red-600 font-medium' : ''}`}>
                            {new Date(t.date_planifiee).toLocaleString('fr-FR', {
                              day: '2-digit',
                              month: '2-digit',
                              year: 'numeric',
                              hour: '2-digit',
                              minute: '2-digit',
                            })}
                          </span>
                        </div>
                      </td>
                      <td className="px-4 py-3">
                        <span className={`px-2 py-1 rounded text-xs font-medium ${typeLabels[t.type]?.color}`}>
                          {typeLabels[t.type]?.label}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        <div>
                          <p className="font-medium">{t.produit_numero}</p>
                          {t.produit_nom && <p className="text-sm text-gray-500">{t.produit_nom}</p>}
                        </div>
                      </td>
                      <td className="px-4 py-3 text-sm">
                        {t.secteur_source && <span>{t.secteur_source}</span>}
                        {t.secteur_source && t.secteur_destination && <span className="mx-1">→</span>}
                        {t.secteur_destination && <span>{t.secteur_destination}</span>}
                      </td>
                      <td className="px-4 py-3 font-medium">
                        {t.quantite} {t.unite_mesure}
                      </td>
                      <td className="px-4 py-3">
                        <span className={`inline-flex items-center gap-1 px-2 py-1 rounded text-xs font-medium ${statutInfo?.color}`}>
                          <StatusIcon size={12} />
                          {statutInfo?.label}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex gap-1">
                          {t.statut === 'planifie' && (
                            <>
                              <button
                                onClick={() => executeTransfert(t.id)}
                                className="p-1.5 text-green-600 hover:bg-green-50 rounded"
                                title="Exécuter"
                              >
                                <Play size={16} />
                              </button>
                              <button
                                onClick={() => openModal(t)}
                                className="p-1.5 text-blue-600 hover:bg-blue-50 rounded"
                                title="Modifier"
                              >
                                <Edit size={16} />
                              </button>
                              <button
                                onClick={() => cancelTransfert(t.id)}
                                className="p-1.5 text-orange-600 hover:bg-orange-50 rounded"
                                title="Annuler"
                              >
                                <X size={16} />
                              </button>
                            </>
                          )}
                          {t.statut !== 'execute' && (
                            <button
                              onClick={() => deleteTransfert(t.id)}
                              className="p-1.5 text-red-600 hover:bg-red-50 rounded"
                              title="Supprimer"
                            >
                              <Trash2 size={16} />
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
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
                {editingId ? 'Modifier le transfert' : 'Planifier un transfert'}
              </h2>
              <button onClick={() => setShowModal(false)} className="p-1 hover:bg-gray-100 rounded">
                ✕
              </button>
            </div>
            <form onSubmit={handleSubmit} className="p-4 space-y-4">
              <div className="grid grid-cols-2 gap-4">
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
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Date planifiée *</label>
                  <input
                    type="datetime-local"
                    value={form.date_planifiee}
                    onChange={(e) => setForm({ ...form, date_planifiee: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                    required
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">N° Produit *</label>
                  <input
                    type="text"
                    value={form.produit_numero}
                    onChange={(e) => setForm({ ...form, produit_numero: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
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
                    <option value="carton">Carton</option>
                    <option value="palette">Palette</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Employé responsable *</label>
                <input
                  type="text"
                  value={form.employe}
                  onChange={(e) => setForm({ ...form, employe: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg"
                  required
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Motif</label>
                <input
                  type="text"
                  value={form.motif}
                  onChange={(e) => setForm({ ...form, motif: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea
                  value={form.notes}
                  onChange={(e) => setForm({ ...form, notes: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg"
                  rows={2}
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
                  {editingId ? 'Mettre à jour' : 'Planifier'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}
