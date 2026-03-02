import { useEffect, useState } from 'react';
import { AlertTriangle, AlertCircle, Info, Bell, Settings, RefreshCw, Plus, X, Save } from 'lucide-react';
import { getProduits } from '@/services/api';
import type { Produit } from '@/types';

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

interface Alerte {
  id: number;
  numero: string;
  description: string;
  type: string;
  unite_mesure: string;
  stock_actuel: number;
  seuil_alerte: number;
  deficit: number;
  pourcentage: number;
  criticite: 'critique' | 'warning' | 'info';
}

interface AlerteStats {
  total_produits_surveilles: number;
  en_alerte: number;
  critiques: number;
  warnings: number;
  ok: number;
}

interface ProduitAvecSeuil {
  id: number;
  numero: string;
  description: string;
  seuil_alerte: number | null;
}

export default function AlertesPage() {
  const [alertes, setAlertes] = useState<Alerte[]>([]);
  const [stats, setStats] = useState<AlerteStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [filterCriticite, setFilterCriticite] = useState<string>('');
  const [showConfig, setShowConfig] = useState(false);
  const [produits, setProduits] = useState<ProduitAvecSeuil[]>([]);
  const [loadingProduits, setLoadingProduits] = useState(false);
  const [seuilEdits, setSeuilEdits] = useState<Record<number, string>>({});
  const [saving, setSaving] = useState(false);

  async function loadData() {
    setLoading(true);
    try {
      const [alertesRes, statsRes] = await Promise.all([
        fetch(`${API_BASE_URL}/alertes`, { headers: getAuthHeaders() }),
        fetch(`${API_BASE_URL}/alertes/stats`, { headers: getAuthHeaders() }),
      ]);

      if (alertesRes.ok) {
        const data = await alertesRes.json();
        setAlertes(data.alertes || []);
      }
      if (statsRes.ok) {
        setStats(await statsRes.json());
      }
    } catch (error) {
      console.error('Erreur chargement alertes:', error);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadData();
  }, []);

  async function loadProduits() {
    setLoadingProduits(true);
    try {
      const data = await getProduits();
      setProduits(data.filter((p: Produit) => p.id !== undefined).map((p: Produit) => ({
        id: p.id as number,
        numero: p.numero,
        description: p.description,
        seuil_alerte: null,
      })));
    } catch (error) {
      console.error('Erreur chargement produits:', error);
    } finally {
      setLoadingProduits(false);
    }
  }

  function openConfig() {
    setShowConfig(true);
    loadProduits();
  }

  async function saveSeuil(produitId: number) {
    const seuil = seuilEdits[produitId];
    if (seuil === undefined) return;

    setSaving(true);
    try {
      const res = await fetch(`${API_BASE_URL}/alertes/seuil/${produitId}`, {
        method: 'PUT',
        headers: { ...getAuthHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ seuil_alerte: parseFloat(seuil) || 0 }),
      });
      if (res.ok) {
        loadData();
        setSeuilEdits(prev => {
          const copy = { ...prev };
          delete copy[produitId];
          return copy;
        });
      }
    } catch (error) {
      console.error('Erreur sauvegarde seuil:', error);
    } finally {
      setSaving(false);
    }
  }

  const filteredAlertes = filterCriticite
    ? alertes.filter(a => a.criticite === filterCriticite)
    : alertes;

  const getCriticiteStyle = (criticite: string) => {
    switch (criticite) {
      case 'critique':
        return 'bg-red-100 text-red-700 border-red-200';
      case 'warning':
        return 'bg-yellow-100 text-yellow-700 border-yellow-200';
      default:
        return 'bg-blue-100 text-blue-700 border-blue-200';
    }
  };

  const getCriticiteIcon = (criticite: string) => {
    switch (criticite) {
      case 'critique':
        return <AlertTriangle className="text-red-600" size={20} />;
      case 'warning':
        return <AlertCircle className="text-yellow-600" size={20} />;
      default:
        return <Info className="text-blue-600" size={20} />;
    }
  };

  if (loading) {
    return <div className="text-gray-500">Chargement des alertes...</div>;
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-800">Alertes de Stock</h1>
          <p className="text-gray-500">Produits en dessous du seuil d'alerte</p>
        </div>
        <div className="flex gap-2">
          <button
            onClick={openConfig}
            className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
          >
            <Plus size={18} />
            Configurer seuils
          </button>
          <button
            onClick={loadData}
            className="flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50"
          >
            <RefreshCw size={18} />
            Actualiser
          </button>
        </div>
      </div>

      {/* Modal de configuration des seuils */}
      {showConfig && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-3xl max-h-[80vh] overflow-hidden">
            <div className="flex items-center justify-between p-4 border-b">
              <h2 className="text-lg font-semibold">Configurer les seuils d'alerte</h2>
              <button onClick={() => setShowConfig(false)} className="p-1 hover:bg-gray-100 rounded">
                <X size={20} />
              </button>
            </div>
            <div className="p-4 overflow-y-auto max-h-[60vh]">
              {loadingProduits ? (
                <p className="text-gray-500 text-center py-8">Chargement des produits...</p>
              ) : (
                <table className="w-full">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-4 py-2 text-left text-sm font-medium text-gray-600">Numéro</th>
                      <th className="px-4 py-2 text-left text-sm font-medium text-gray-600">Description</th>
                      <th className="px-4 py-2 text-right text-sm font-medium text-gray-600">Seuil minimum</th>
                      <th className="px-4 py-2 text-center text-sm font-medium text-gray-600">Action</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y">
                    {produits.map((produit) => (
                      <tr key={produit.id} className="hover:bg-gray-50">
                        <td className="px-4 py-2 text-sm font-mono">{produit.numero}</td>
                        <td className="px-4 py-2 text-sm">{produit.description}</td>
                        <td className="px-4 py-2">
                          <input
                            type="number"
                            min="0"
                            className="w-24 px-2 py-1 border rounded text-right"
                            placeholder="0"
                            value={seuilEdits[produit.id] ?? ''}
                            onChange={(e) => setSeuilEdits(prev => ({ ...prev, [produit.id]: e.target.value }))}
                          />
                        </td>
                        <td className="px-4 py-2 text-center">
                          <button
                            onClick={() => saveSeuil(produit.id)}
                            disabled={saving || seuilEdits[produit.id] === undefined}
                            className="px-3 py-1 bg-green-600 text-white text-sm rounded hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
                          >
                            <Save size={14} className="inline mr-1" />
                            Sauver
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Stats */}
      {stats && (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div className="bg-white rounded-xl shadow-sm p-5">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-blue-100 rounded-lg">
                <Settings className="text-blue-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-gray-800">{stats.total_produits_surveilles}</p>
                <p className="text-sm text-gray-500">Produits surveillés</p>
              </div>
            </div>
          </div>
          <div 
            className="bg-white rounded-xl shadow-sm p-5 cursor-pointer hover:shadow-md transition-shadow"
            onClick={() => setFilterCriticite(filterCriticite === 'critique' ? '' : 'critique')}
          >
            <div className="flex items-center gap-3">
              <div className="p-3 bg-red-100 rounded-lg">
                <AlertTriangle className="text-red-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-red-600">{stats.critiques}</p>
                <p className="text-sm text-gray-500">Critiques</p>
              </div>
            </div>
          </div>
          <div 
            className="bg-white rounded-xl shadow-sm p-5 cursor-pointer hover:shadow-md transition-shadow"
            onClick={() => setFilterCriticite(filterCriticite === 'warning' ? '' : 'warning')}
          >
            <div className="flex items-center gap-3">
              <div className="p-3 bg-yellow-100 rounded-lg">
                <AlertCircle className="text-yellow-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-yellow-600">{stats.warnings}</p>
                <p className="text-sm text-gray-500">Avertissements</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-5">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-green-100 rounded-lg">
                <Bell className="text-green-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-green-600">{stats.ok}</p>
                <p className="text-sm text-gray-500">Stock OK</p>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Filtre actif */}
      {filterCriticite && (
        <div className="flex items-center gap-2">
          <span className="text-sm text-gray-500">Filtre actif:</span>
          <span className={`px-3 py-1 rounded-full text-sm font-medium ${getCriticiteStyle(filterCriticite)}`}>
            {filterCriticite === 'critique' ? 'Critiques' : 'Avertissements'}
          </span>
          <button
            onClick={() => setFilterCriticite('')}
            className="text-sm text-blue-600 hover:underline"
          >
            Effacer
          </button>
        </div>
      )}

      {/* Liste des alertes */}
      <div className="bg-white rounded-xl shadow-sm overflow-hidden">
        {filteredAlertes.length === 0 ? (
          <div className="p-8 text-center">
            <Bell className="mx-auto text-gray-300 mb-4" size={48} />
            <p className="text-gray-500">
              {alertes.length === 0 
                ? "Aucune alerte de stock. Tous les produits sont au-dessus de leur seuil."
                : "Aucune alerte correspondant au filtre."}
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 border-b">
                <tr>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Statut</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Produit</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Description</th>
                  <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">Stock actuel</th>
                  <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">Seuil</th>
                  <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">Déficit</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Niveau</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {filteredAlertes.map((alerte) => (
                  <tr key={alerte.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3">
                      {getCriticiteIcon(alerte.criticite)}
                    </td>
                    <td className="px-4 py-3 text-sm font-mono font-medium">{alerte.numero}</td>
                    <td className="px-4 py-3 text-sm text-gray-600">{alerte.description}</td>
                    <td className="px-4 py-3 text-sm text-right font-medium">
                      {alerte.stock_actuel} {alerte.unite_mesure}
                    </td>
                    <td className="px-4 py-3 text-sm text-right text-gray-500">
                      {alerte.seuil_alerte} {alerte.unite_mesure}
                    </td>
                    <td className="px-4 py-3 text-sm text-right font-medium text-red-600">
                      -{alerte.deficit}
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        <div className="w-24 h-2 bg-gray-200 rounded-full overflow-hidden">
                          <div 
                            className={`h-full rounded-full ${
                              alerte.criticite === 'critique' ? 'bg-red-500' :
                              alerte.criticite === 'warning' ? 'bg-yellow-500' : 'bg-blue-500'
                            }`}
                            style={{ width: `${Math.min(alerte.pourcentage, 100)}%` }}
                          />
                        </div>
                        <span className="text-xs text-gray-500">{alerte.pourcentage}%</span>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
