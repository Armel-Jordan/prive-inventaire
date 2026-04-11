import { useState, useEffect } from 'react';
import { TrendingUp, AlertTriangle, ShoppingCart, Package } from 'lucide-react';
import PageSkeleton from '@/components/PageSkeleton';
import EmptyState from '@/components/EmptyState';

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

interface PrevisionProduit {
  id: number;
  numero: string;
  description: string;
  unite_mesure: string;
  stock_actuel: number;
  stock_min: number | null;
  consommation_jour: number;
  jours_restants: number | null;
  statut: 'critique' | 'bas' | 'ok';
}

export default function PrevisionsStockPage() {
  const [produits, setProduits] = useState<PrevisionProduit[]>([]);
  const [stats, setStats] = useState({ total: 0, critiques: 0, bas: 0, ok: 0 });
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState<'tous' | 'critique' | 'bas' | 'ok'>('tous');

  useEffect(() => {
    loadPrevisions();
  }, []);

  async function loadPrevisions() {
    setLoading(true);
    try {
      const res = await fetch(`${API_BASE_URL}/alertes/previsions`, {
        headers: getAuthHeaders(),
      });
      if (res.ok) {
        const data = await res.json();
        setProduits(data.produits);
        setStats({ total: data.total, critiques: data.critiques, bas: data.bas, ok: data.ok });
      }
    } catch {
      /* ignore */
    } finally {
      setLoading(false);
    }
  }

  const filtered = produits.filter(p => filter === 'tous' || p.statut === filter);

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Prévisions de Stock</h1>
      </div>

      {/* Résumé */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
          <div className="flex items-center gap-3">
            <Package className="text-blue-600" size={24} />
            <div>
              <p className="text-sm text-gray-500">Total produits</p>
              <p className="text-xl font-bold dark:text-white">{stats.total}</p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
          <div className="flex items-center gap-3">
            <AlertTriangle className="text-red-600" size={24} />
            <div>
              <p className="text-sm text-gray-500">Stock critique</p>
              <p className="text-xl font-bold text-red-600">{stats.critiques}</p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
          <div className="flex items-center gap-3">
            <TrendingUp className="text-yellow-600" size={24} />
            <div>
              <p className="text-sm text-gray-500">Stock bas</p>
              <p className="text-xl font-bold text-yellow-600">{stats.bas}</p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
          <div className="flex items-center gap-3">
            <ShoppingCart className="text-purple-600" size={24} />
            <div>
              <p className="text-sm text-gray-500">À commander</p>
              <p className="text-xl font-bold text-purple-600">{stats.critiques + stats.bas}</p>
            </div>
          </div>
        </div>
      </div>

      {/* Filtres */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 mb-6">
        <div className="flex gap-2">
          {[
            { key: 'tous', label: 'Tous', active: 'bg-gray-600 text-white' },
            { key: 'critique', label: 'Critique', active: 'bg-red-600 text-white' },
            { key: 'bas', label: 'Stock bas', active: 'bg-yellow-500 text-white' },
            { key: 'ok', label: 'OK', active: 'bg-green-600 text-white' },
          ].map(f => (
            <button
              key={f.key}
              onClick={() => setFilter(f.key as typeof filter)}
              className={`px-4 py-2 rounded-lg font-medium ${
                filter === f.key
                  ? f.active
                  : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'
              }`}
            >
              {f.label}
            </button>
          ))}
        </div>
      </div>

      {/* Tableau */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
        {loading ? (
          <PageSkeleton kpis={0} rows={5} />
        ) : filtered.length === 0 ? (
          <EmptyState icon="📦" title="Aucun produit trouvé" subtitle="Aucun produit ne correspond à ce filtre" />
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50 dark:bg-gray-700">
              <tr>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Produit</th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Stock actuel</th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Stock min</th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Conso/jour</th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Jours restants</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Statut</th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Suggestion</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
              {filtered.map((produit) => {
                const suggestion = produit.statut !== 'ok' && produit.stock_min !== null
                  ? Math.max(0, produit.stock_min * 2 - produit.stock_actuel)
                  : 0;
                return (
                  <tr key={produit.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td className="px-4 py-3">
                      <div>
                        <p className="font-medium text-gray-900 dark:text-white">{produit.description}</p>
                        <p className="text-xs text-gray-500">{produit.numero}</p>
                      </div>
                    </td>
                    <td className="px-4 py-3 text-right text-sm text-gray-900 dark:text-white">
                      {produit.stock_actuel} {produit.unite_mesure}
                    </td>
                    <td className="px-4 py-3 text-right text-sm text-gray-600 dark:text-gray-300">
                      {produit.stock_min !== null ? `${produit.stock_min} ${produit.unite_mesure}` : '—'}
                    </td>
                    <td className="px-4 py-3 text-right text-sm text-gray-600 dark:text-gray-300">
                      {produit.consommation_jour > 0 ? produit.consommation_jour : '—'}
                    </td>
                    <td className="px-4 py-3 text-right text-sm">
                      {produit.jours_restants !== null ? (
                        <span className={
                          produit.jours_restants <= 7 ? 'text-red-600 font-bold' :
                          produit.jours_restants <= 14 ? 'text-yellow-600 font-medium' :
                          'text-gray-600 dark:text-gray-300'
                        }>
                          {produit.jours_restants} j
                        </span>
                      ) : '—'}
                    </td>
                    <td className="px-4 py-3 text-center">
                      <span className={`px-2 py-1 text-xs rounded-full ${
                        produit.statut === 'critique' ? 'bg-red-100 text-red-800' :
                        produit.statut === 'bas' ? 'bg-yellow-100 text-yellow-800' :
                        'bg-green-100 text-green-800'
                      }`}>
                        {produit.statut === 'critique' ? 'Critique' : produit.statut === 'bas' ? 'Bas' : 'OK'}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-right">
                      {suggestion > 0 && (
                        <span className="text-sm font-medium text-purple-600">
                          Commander {suggestion} {produit.unite_mesure}
                        </span>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
