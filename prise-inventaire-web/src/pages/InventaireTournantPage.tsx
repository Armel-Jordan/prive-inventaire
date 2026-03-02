import { useEffect, useState } from 'react';
import { RefreshCw, MapPin, AlertTriangle, CheckCircle, Calendar, Clock, TrendingUp } from 'lucide-react';

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const STORAGE_KEY = 'prise_auth';

function getAuthHeaders(): Record<string, string> {
  const headers: Record<string, string> = { 'Accept': 'application/json' };
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

interface Suggestion {
  secteur: string;
  nom: string;
  dernier_scan: string | null;
  jours_depuis_scan: number;
  mouvements_depuis_scan: number;
  score_priorite: number;
  raison: string;
}

interface Stats {
  total_secteurs: number;
  scannes_ce_mois: number;
  jamais_scannes: number;
  non_scannes_30_jours: number;
  couverture_mois: number;
}

interface PlanningJour {
  date: string;
  jour: string;
  secteurs: { code: string; nom: string }[];
}

function getPriorityColor(score: number): string {
  if (score >= 100) return 'bg-red-100 border-red-300 text-red-700';
  if (score >= 50) return 'bg-orange-100 border-orange-300 text-orange-700';
  if (score >= 20) return 'bg-yellow-100 border-yellow-300 text-yellow-700';
  return 'bg-green-100 border-green-300 text-green-700';
}

function getPriorityLabel(score: number): string {
  if (score >= 100) return 'Critique';
  if (score >= 50) return 'Haute';
  if (score >= 20) return 'Moyenne';
  return 'Basse';
}

export default function InventaireTournantPage() {
  const [activeTab, setActiveTab] = useState<'suggestions' | 'planning'>('suggestions');
  const [suggestions, setSuggestions] = useState<Suggestion[]>([]);
  const [stats, setStats] = useState<Stats | null>(null);
  const [planning, setPlanning] = useState<PlanningJour[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    loadData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeTab]);

  async function loadData() {
    setLoading(true);
    try {
      if (activeTab === 'suggestions') {
        const [suggestionsRes, statsRes] = await Promise.all([
          fetch(`${API_BASE_URL}/inventaire-tournant/suggestions`, { headers: getAuthHeaders() }),
          fetch(`${API_BASE_URL}/inventaire-tournant/stats`, { headers: getAuthHeaders() }),
        ]);

        if (suggestionsRes.ok) {
          const data = await suggestionsRes.json();
          setSuggestions(data.suggestions);
        }
        if (statsRes.ok) {
          setStats(await statsRes.json());
        }
      } else if (activeTab === 'planning') {
        const response = await fetch(`${API_BASE_URL}/inventaire-tournant/planning`, {
          headers: getAuthHeaders(),
        });
        if (response.ok) {
          const data = await response.json();
          setPlanning(data.planning);
        }
      }
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-800">Inventaire Tournant</h1>
          <p className="text-gray-500">Suggestions de secteurs à vérifier</p>
        </div>
        <button
          onClick={loadData}
          className="flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50"
        >
          <RefreshCw size={18} />
          Actualiser
        </button>
      </div>

      {/* Stats */}
      {stats && (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div className="bg-white rounded-xl shadow-sm p-5">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-blue-100 rounded-lg">
                <MapPin className="text-blue-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-gray-800">{stats.total_secteurs}</p>
                <p className="text-sm text-gray-500">Secteurs actifs</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-5">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-green-100 rounded-lg">
                <CheckCircle className="text-green-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-green-600">{stats.scannes_ce_mois}</p>
                <p className="text-sm text-gray-500">Scannés ce mois</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-5">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-red-100 rounded-lg">
                <AlertTriangle className="text-red-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-red-600">{stats.jamais_scannes}</p>
                <p className="text-sm text-gray-500">Jamais scannés</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-5">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-purple-100 rounded-lg">
                <TrendingUp className="text-purple-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-purple-600">{stats.couverture_mois}%</p>
                <p className="text-sm text-gray-500">Couverture mois</p>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Onglets */}
      <div className="flex gap-2 border-b">
        <button
          onClick={() => setActiveTab('suggestions')}
          className={`flex items-center gap-2 px-4 py-3 border-b-2 transition-colors ${
            activeTab === 'suggestions'
              ? 'border-blue-600 text-blue-600'
              : 'border-transparent text-gray-500 hover:text-gray-700'
          }`}
        >
          <AlertTriangle size={18} />
          Suggestions prioritaires
        </button>
        <button
          onClick={() => setActiveTab('planning')}
          className={`flex items-center gap-2 px-4 py-3 border-b-2 transition-colors ${
            activeTab === 'planning'
              ? 'border-blue-600 text-blue-600'
              : 'border-transparent text-gray-500 hover:text-gray-700'
          }`}
        >
          <Calendar size={18} />
          Planning mensuel
        </button>
      </div>

      {loading ? (
        <div className="bg-white rounded-xl shadow-sm p-8 text-center text-gray-500">
          Chargement...
        </div>
      ) : (
        <>
          {/* Suggestions */}
          {activeTab === 'suggestions' && (
            <div className="space-y-4">
              {suggestions.length === 0 ? (
                <div className="bg-white rounded-xl shadow-sm p-8 text-center text-gray-500">
                  <CheckCircle className="mx-auto text-green-400 mb-4" size={48} />
                  <p>Tous les secteurs sont à jour !</p>
                </div>
              ) : (
                suggestions.map((s, index) => (
                  <div
                    key={s.secteur}
                    className={`bg-white rounded-xl shadow-sm p-4 border-l-4 ${getPriorityColor(s.score_priorite)}`}
                  >
                    <div className="flex items-start justify-between">
                      <div className="flex items-start gap-4">
                        <div className="flex items-center justify-center w-8 h-8 bg-gray-100 rounded-full text-gray-600 font-bold">
                          {index + 1}
                        </div>
                        <div>
                          <div className="flex items-center gap-3 mb-1">
                            <h3 className="font-bold text-lg">{s.secteur}</h3>
                            <span className="text-gray-500">{s.nom}</span>
                            <span className={`px-2 py-0.5 rounded text-xs font-medium ${getPriorityColor(s.score_priorite)}`}>
                              {getPriorityLabel(s.score_priorite)}
                            </span>
                          </div>
                          <p className="text-sm text-gray-600 mb-2">{s.raison}</p>
                          <div className="flex items-center gap-4 text-sm text-gray-500">
                            <span className="flex items-center gap-1">
                              <Clock size={14} />
                              {s.dernier_scan
                                ? `Dernier scan: ${new Date(s.dernier_scan).toLocaleDateString('fr-FR')}`
                                : 'Jamais scanné'}
                            </span>
                            <span>
                              {s.jours_depuis_scan} jour{s.jours_depuis_scan > 1 ? 's' : ''} depuis dernier scan
                            </span>
                            <span>
                              {s.mouvements_depuis_scan} mouvement{s.mouvements_depuis_scan > 1 ? 's' : ''} depuis
                            </span>
                          </div>
                        </div>
                      </div>
                      <div className="text-right">
                        <p className="text-2xl font-bold text-gray-400">{s.score_priorite}</p>
                        <p className="text-xs text-gray-400">score</p>
                      </div>
                    </div>
                  </div>
                ))
              )}
            </div>
          )}

          {/* Planning */}
          {activeTab === 'planning' && (
            <div className="bg-white rounded-xl shadow-sm overflow-hidden">
              {planning.length === 0 ? (
                <div className="p-8 text-center text-gray-500">
                  Aucun planning disponible
                </div>
              ) : (
                <div className="divide-y">
                  {planning.map((jour) => (
                    <div key={jour.date} className="p-4 hover:bg-gray-50">
                      <div className="flex items-start gap-4">
                        <div className="text-center min-w-[80px]">
                          <p className="text-sm text-gray-500">{jour.jour.split(' ')[0]}</p>
                          <p className="text-2xl font-bold text-gray-800">{jour.jour.split(' ')[1]}</p>
                        </div>
                        <div className="flex-1">
                          <div className="flex flex-wrap gap-2">
                            {jour.secteurs.map((secteur) => (
                              <span
                                key={secteur.code}
                                className="inline-flex items-center gap-1 px-3 py-1.5 bg-blue-50 text-blue-700 rounded-lg text-sm"
                              >
                                <MapPin size={14} />
                                <span className="font-medium">{secteur.code}</span>
                                <span className="text-blue-500">- {secteur.nom}</span>
                              </span>
                            ))}
                          </div>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}
        </>
      )}
    </div>
  );
}
