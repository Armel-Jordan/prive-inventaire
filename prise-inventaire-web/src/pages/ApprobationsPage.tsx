import { useEffect, useState } from 'react';
import { ShieldCheck, Clock, CheckCircle, XCircle, Settings, AlertTriangle } from 'lucide-react';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';

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

interface Approbation {
  id: number;
  type_mouvement: 'arrivage' | 'transfert' | 'sortie';
  produit_numero: string;
  produit_nom: string | null;
  secteur_source: string | null;
  secteur_destination: string | null;
  quantite: number;
  unite_mesure: string | null;
  motif: string | null;
  demandeur: string;
  statut: 'en_attente' | 'approuve' | 'rejete';
  approbateur: string | null;
  date_decision: string | null;
  commentaire_approbateur: string | null;
  seuil_declenchement: number | null;
  created_at: string;
}

interface Seuil {
  id: number;
  type_mouvement: string;
  seuil_quantite: number;
  actif: boolean;
}

interface Stats {
  en_attente: number;
  approuvees_ce_mois: number;
  rejetees_ce_mois: number;
}

const typeLabels: Record<string, { label: string; color: string }> = {
  arrivage: { label: 'Arrivage', color: 'bg-green-100 text-green-700' },
  transfert: { label: 'Transfert', color: 'bg-blue-100 text-blue-700' },
  sortie: { label: 'Sortie', color: 'bg-red-100 text-red-700' },
};

const statutLabels: Record<string, { label: string; color: string; icon: typeof Clock }> = {
  en_attente: { label: 'En attente', color: 'bg-yellow-100 text-yellow-700', icon: Clock },
  approuve: { label: 'Approuvé', color: 'bg-green-100 text-green-700', icon: CheckCircle },
  rejete: { label: 'Rejeté', color: 'bg-red-100 text-red-700', icon: XCircle },
};

export default function ApprobationsPage() {
  const { toasts, toast, dismiss } = useToast();
  const [approbations, setApprobations] = useState<Approbation[]>([]);
  const [seuils, setSeuils] = useState<Seuil[]>([]);
  const [stats, setStats] = useState<Stats | null>(null);
  const [loading, setLoading] = useState(true);
  const [filterStatut, setFilterStatut] = useState('en_attente');
  const [showSettings, setShowSettings] = useState(false);
  const [showDecisionModal, setShowDecisionModal] = useState(false);
  const [selectedApprobation, setSelectedApprobation] = useState<Approbation | null>(null);
  const [decisionType, setDecisionType] = useState<'approve' | 'reject'>('approve');
  const [commentaire, setCommentaire] = useState('');

  useEffect(() => {
    loadData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filterStatut]);

  async function loadData() {
    setLoading(true);
    try {
      const params = filterStatut ? `?statut=${filterStatut}` : '';
      const [approbationsRes, statsRes, seuilsRes] = await Promise.all([
        fetch(`${API_BASE_URL}/approbations${params}`, { headers: getAuthHeaders() }),
        fetch(`${API_BASE_URL}/approbations/stats`, { headers: getAuthHeaders() }),
        fetch(`${API_BASE_URL}/approbations/settings`, { headers: getAuthHeaders() }),
      ]);

      if (approbationsRes.ok) setApprobations(await approbationsRes.json());
      if (statsRes.ok) setStats(await statsRes.json());
      if (seuilsRes.ok) setSeuils(await seuilsRes.json());
    } catch (error) {
      toast('Erreur de chargement des données', 'error');
    } finally {
      setLoading(false);
    }
  }

  function openDecisionModal(approbation: Approbation, type: 'approve' | 'reject') {
    setSelectedApprobation(approbation);
    setDecisionType(type);
    setCommentaire('');
    setShowDecisionModal(true);
  }

  async function handleDecision() {
    if (!selectedApprobation) return;
    if (decisionType === 'reject' && !commentaire.trim()) {
      toast('Un commentaire est requis pour rejeter', 'error'); return;
    }

    try {
      const response = await fetch(
        `${API_BASE_URL}/approbations/${selectedApprobation.id}/${decisionType}`,
        {
          method: 'POST',
          headers: getAuthHeaders(),
          body: JSON.stringify({
            approbateur: 'Admin Web',
            commentaire: commentaire || null,
          }),
        }
      );

      if (response.ok) {
        setShowDecisionModal(false);
        loadData();
      } else {
        const error = await response.json();
        toast(error instanceof Error ? error.message : 'Une erreur est survenue', 'error');
      }
    } catch (error) {
      toast('Une erreur est survenue', 'error');
    }
  }

  async function saveSeuils() {
    try {
      const response = await fetch(`${API_BASE_URL}/approbations/settings`, {
        method: 'PUT',
        headers: getAuthHeaders(),
        body: JSON.stringify({ seuils }),
      });

      if (response.ok) {
        setShowSettings(false);
        toast('Seuils mis à jour avec succès', 'success');
      }
    } catch (error) {
      toast('Une erreur est survenue', 'error');
    }
  }

  if (loading && approbations.length === 0) {
    return <div className="text-gray-500">Chargement...</div>;
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-800">Approbations</h1>
          <p className="text-gray-500">Workflow d'approbation pour les gros mouvements</p>
        </div>
        <button
          onClick={() => setShowSettings(true)}
          className="flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50"
        >
          <Settings size={18} />
          Paramètres
        </button>
      </div>

      {/* Stats */}
      {stats && (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div className="bg-white rounded-xl shadow-sm p-5">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-yellow-100 rounded-lg">
                <Clock className="text-yellow-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-yellow-600">{stats.en_attente}</p>
                <p className="text-sm text-gray-500">En attente</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-5">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-green-100 rounded-lg">
                <CheckCircle className="text-green-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-green-600">{stats.approuvees_ce_mois}</p>
                <p className="text-sm text-gray-500">Approuvées (mois)</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-5">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-red-100 rounded-lg">
                <XCircle className="text-red-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-red-600">{stats.rejetees_ce_mois}</p>
                <p className="text-sm text-gray-500">Rejetées (mois)</p>
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
            {['', 'en_attente', 'approuve', 'rejete'].map((statut) => (
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
        {approbations.length === 0 ? (
          <div className="p-8 text-center text-gray-500">
            <ShieldCheck className="mx-auto text-gray-300 mb-4" size={48} />
            <p>Aucune demande d'approbation</p>
          </div>
        ) : (
          <div className="divide-y">
            {approbations.map((a) => {
              const statutInfo = statutLabels[a.statut];
              const StatusIcon = statutInfo?.icon || Clock;

              return (
                <div key={a.id} className="p-4 hover:bg-gray-50">
                  <div className="flex items-start justify-between">
                    <div className="flex-1">
                      <div className="flex items-center gap-3 mb-2">
                        <span className={`px-2 py-1 rounded text-xs font-medium ${typeLabels[a.type_mouvement]?.color}`}>
                          {typeLabels[a.type_mouvement]?.label}
                        </span>
                        <span className={`inline-flex items-center gap-1 px-2 py-1 rounded text-xs font-medium ${statutInfo?.color}`}>
                          <StatusIcon size={12} />
                          {statutInfo?.label}
                        </span>
                        {a.seuil_declenchement && (
                          <span className="text-xs text-gray-400 flex items-center gap-1">
                            <AlertTriangle size={12} />
                            Seuil: {a.seuil_declenchement}
                          </span>
                        )}
                      </div>
                      <div className="flex items-center gap-4">
                        <div>
                          <p className="font-medium">{a.produit_numero}</p>
                          {a.produit_nom && <p className="text-sm text-gray-500">{a.produit_nom}</p>}
                        </div>
                        <div className="text-sm">
                          {a.secteur_source && <span>{a.secteur_source}</span>}
                          {a.secteur_source && a.secteur_destination && <span className="mx-1">→</span>}
                          {a.secteur_destination && <span>{a.secteur_destination}</span>}
                        </div>
                        <div className="font-bold text-lg">
                          {a.quantite} {a.unite_mesure}
                        </div>
                      </div>
                      <div className="text-sm text-gray-500 mt-2">
                        Demandé par <span className="font-medium">{a.demandeur}</span> le{' '}
                        {new Date(a.created_at).toLocaleString('fr-FR')}
                      </div>
                      {a.commentaire_approbateur && (
                        <div className="mt-2 p-2 bg-gray-50 rounded text-sm">
                          <span className="font-medium">{a.approbateur}:</span> {a.commentaire_approbateur}
                        </div>
                      )}
                    </div>
                    {a.statut === 'en_attente' && (
                      <div className="flex gap-2 ml-4">
                        <button
                          onClick={() => openDecisionModal(a, 'approve')}
                          className="px-3 py-1.5 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm flex items-center gap-1"
                        >
                          <CheckCircle size={14} />
                          Approuver
                        </button>
                        <button
                          onClick={() => openDecisionModal(a, 'reject')}
                          className="px-3 py-1.5 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm flex items-center gap-1"
                        >
                          <XCircle size={14} />
                          Rejeter
                        </button>
                      </div>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>

      {/* Modal Décision */}
      {showDecisionModal && selectedApprobation && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
            <div className="p-4 border-b">
              <h2 className="text-lg font-semibold">
                {decisionType === 'approve' ? 'Approuver' : 'Rejeter'} la demande
              </h2>
            </div>
            <div className="p-4 space-y-4">
              <div className="bg-gray-50 rounded-lg p-3">
                <p className="font-medium">{selectedApprobation.produit_numero}</p>
                <p className="text-sm text-gray-500">
                  {selectedApprobation.quantite} {selectedApprobation.unite_mesure} - {selectedApprobation.demandeur}
                </p>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Commentaire {decisionType === 'reject' && '*'}
                </label>
                <textarea
                  value={commentaire}
                  onChange={(e) => setCommentaire(e.target.value)}
                  className="w-full px-3 py-2 border rounded-lg"
                  rows={3}
                  placeholder={decisionType === 'reject' ? 'Raison du rejet...' : 'Optionnel...'}
                  required={decisionType === 'reject'}
                />
              </div>
              <div className="flex gap-3">
                <button
                  onClick={() => setShowDecisionModal(false)}
                  className="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50"
                >
                  Annuler
                </button>
                <button
                  onClick={handleDecision}
                  className={`flex-1 px-4 py-2 text-white rounded-lg ${
                    decisionType === 'approve'
                      ? 'bg-green-600 hover:bg-green-700'
                      : 'bg-red-600 hover:bg-red-700'
                  }`}
                >
                  Confirmer
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Modal Paramètres */}
      {showSettings && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
            <div className="flex items-center justify-between p-4 border-b">
              <h2 className="text-lg font-semibold">Seuils d'approbation</h2>
              <button onClick={() => setShowSettings(false)} className="p-1 hover:bg-gray-100 rounded">
                ✕
              </button>
            </div>
            <div className="p-4 space-y-4">
              <p className="text-sm text-gray-500">
                Les mouvements dépassant ces quantités nécessiteront une approbation.
              </p>
              {seuils.map((seuil, index) => (
                <div key={seuil.type_mouvement} className="flex items-center gap-4">
                  <div className="flex-1">
                    <label className="block text-sm font-medium text-gray-700 mb-1 capitalize">
                      {seuil.type_mouvement}
                    </label>
                    <input
                      type="number"
                      value={seuil.seuil_quantite}
                      onChange={(e) => {
                        const newSeuils = [...seuils];
                        newSeuils[index].seuil_quantite = parseFloat(e.target.value) || 0;
                        setSeuils(newSeuils);
                      }}
                      className="w-full px-3 py-2 border rounded-lg"
                      min="0"
                    />
                  </div>
                  <div className="pt-6">
                    <label className="flex items-center gap-2">
                      <input
                        type="checkbox"
                        checked={seuil.actif}
                        onChange={(e) => {
                          const newSeuils = [...seuils];
                          newSeuils[index].actif = e.target.checked;
                          setSeuils(newSeuils);
                        }}
                        className="rounded"
                      />
                      <span className="text-sm">Actif</span>
                    </label>
                  </div>
                </div>
              ))}
              <div className="flex gap-3 pt-2">
                <button
                  onClick={() => setShowSettings(false)}
                  className="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50"
                >
                  Annuler
                </button>
                <button
                  onClick={saveSeuils}
                  className="flex-1 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700"
                >
                  Enregistrer
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
