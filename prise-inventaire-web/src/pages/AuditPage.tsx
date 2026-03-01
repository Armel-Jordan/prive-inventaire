import { useEffect, useState } from 'react';
import { History, Plus, Pencil, Trash2, Filter, RefreshCw } from 'lucide-react';
import { formatDate } from '@/lib/utils';

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

interface AuditLog {
  id: number;
  action: 'create' | 'update' | 'delete';
  model_type: string;
  model_id: string;
  user_id: string | null;
  user_name: string | null;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  ip_address: string | null;
  created_at: string;
}

interface AuditStats {
  today: number;
  this_week: number;
  this_month: number;
  by_action: Record<string, number>;
  by_model: Record<string, number>;
}

const actionLabels: Record<string, { label: string; color: string; icon: typeof Plus }> = {
  create: { label: 'Création', color: 'bg-green-100 text-green-700', icon: Plus },
  update: { label: 'Modification', color: 'bg-blue-100 text-blue-700', icon: Pencil },
  delete: { label: 'Suppression', color: 'bg-red-100 text-red-700', icon: Trash2 },
};

export default function AuditPage() {
  const [logs, setLogs] = useState<AuditLog[]>([]);
  const [stats, setStats] = useState<AuditStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [filterAction, setFilterAction] = useState('');
  const [filterModel, setFilterModel] = useState('');
  const [expandedId, setExpandedId] = useState<number | null>(null);

  async function loadData() {
    setLoading(true);
    try {
      const params = new URLSearchParams();
      if (filterAction) params.append('action', filterAction);
      if (filterModel) params.append('model_type', filterModel);

      const [logsRes, statsRes] = await Promise.all([
        fetch(`${API_BASE_URL}/audit?${params}`, { headers: getAuthHeaders() }),
        fetch(`${API_BASE_URL}/audit/stats`, { headers: getAuthHeaders() }),
      ]);

      if (logsRes.ok) setLogs(await logsRes.json());
      if (statsRes.ok) setStats(await statsRes.json());
    } catch (error) {
      console.error('Erreur chargement audit:', error);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filterAction, filterModel]);

  const modelTypes = [...new Set(logs.map(l => l.model_type))];

  if (loading && logs.length === 0) {
    return <div className="text-gray-500">Chargement de l'historique...</div>;
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-800">Historique des Modifications</h1>
          <p className="text-gray-500">Traçabilité complète des actions sur les données</p>
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
                <History className="text-blue-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-gray-800">{stats.today}</p>
                <p className="text-sm text-gray-500">Aujourd'hui</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-5">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-green-100 rounded-lg">
                <Plus className="text-green-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-green-600">{stats.by_action?.create || 0}</p>
                <p className="text-sm text-gray-500">Créations (mois)</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-5">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-blue-100 rounded-lg">
                <Pencil className="text-blue-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-blue-600">{stats.by_action?.update || 0}</p>
                <p className="text-sm text-gray-500">Modifications (mois)</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-5">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-red-100 rounded-lg">
                <Trash2 className="text-red-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-red-600">{stats.by_action?.delete || 0}</p>
                <p className="text-sm text-gray-500">Suppressions (mois)</p>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Filtres */}
      <div className="bg-white rounded-xl shadow-sm p-4">
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex items-center gap-2">
            <Filter size={18} className="text-gray-400" />
            <span className="text-sm font-medium text-gray-700">Filtres:</span>
          </div>
          <select
            value={filterAction}
            onChange={(e) => setFilterAction(e.target.value)}
            className="border rounded-lg px-3 py-2 text-sm"
          >
            <option value="">Toutes les actions</option>
            <option value="create">Créations</option>
            <option value="update">Modifications</option>
            <option value="delete">Suppressions</option>
          </select>
          <select
            value={filterModel}
            onChange={(e) => setFilterModel(e.target.value)}
            className="border rounded-lg px-3 py-2 text-sm"
          >
            <option value="">Tous les types</option>
            {modelTypes.map(type => (
              <option key={type} value={type}>{type}</option>
            ))}
          </select>
        </div>
      </div>

      {/* Liste des logs */}
      <div className="bg-white rounded-xl shadow-sm overflow-hidden">
        {logs.length === 0 ? (
          <div className="p-8 text-center text-gray-500">
            <History className="mx-auto text-gray-300 mb-4" size={48} />
            <p>Aucun historique trouvé</p>
          </div>
        ) : (
          <div className="divide-y">
            {logs.map((log) => {
              const actionInfo = actionLabels[log.action] || actionLabels.update;
              const Icon = actionInfo.icon;
              const isExpanded = expandedId === log.id;

              return (
                <div key={log.id} className="p-4 hover:bg-gray-50">
                  <div 
                    className="flex items-center justify-between cursor-pointer"
                    onClick={() => setExpandedId(isExpanded ? null : log.id)}
                  >
                    <div className="flex items-center gap-4">
                      <div className={`p-2 rounded-lg ${actionInfo.color.split(' ')[0]}`}>
                        <Icon size={18} className={actionInfo.color.split(' ')[1]} />
                      </div>
                      <div>
                        <div className="flex items-center gap-2">
                          <span className={`px-2 py-0.5 rounded text-xs font-medium ${actionInfo.color}`}>
                            {actionInfo.label}
                          </span>
                          <span className="font-medium text-gray-800">{log.model_type}</span>
                          {log.model_id && (
                            <span className="text-gray-500 font-mono text-sm">#{log.model_id}</span>
                          )}
                        </div>
                        <div className="text-sm text-gray-500 mt-1">
                          Par {log.user_name || log.user_id || 'Système'} • {formatDate(log.created_at)}
                        </div>
                      </div>
                    </div>
                    <span className="text-gray-400 text-sm">
                      {isExpanded ? '▲' : '▼'}
                    </span>
                  </div>

                  {isExpanded && (
                    <div className="mt-4 pl-14 grid grid-cols-1 md:grid-cols-2 gap-4">
                      {log.old_values && Object.keys(log.old_values).length > 0 && (
                        <div>
                          <p className="text-xs font-medium text-gray-500 mb-2">Anciennes valeurs</p>
                          <pre className="bg-red-50 p-3 rounded-lg text-xs overflow-auto max-h-40">
                            {JSON.stringify(log.old_values, null, 2)}
                          </pre>
                        </div>
                      )}
                      {log.new_values && Object.keys(log.new_values).length > 0 && (
                        <div>
                          <p className="text-xs font-medium text-gray-500 mb-2">Nouvelles valeurs</p>
                          <pre className="bg-green-50 p-3 rounded-lg text-xs overflow-auto max-h-40">
                            {JSON.stringify(log.new_values, null, 2)}
                          </pre>
                        </div>
                      )}
                      {log.ip_address && (
                        <div className="col-span-2 text-xs text-gray-400">
                          IP: {log.ip_address}
                        </div>
                      )}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}
