import { useState, useEffect } from 'react';
import { TrendingUp, AlertTriangle, ShoppingCart, Package } from 'lucide-react';
import { getProduits } from '../services/api';
import type { Produit } from '../types';

interface ProduitAvecStock extends Produit {
  stock_actuel?: number;
  stock_min?: number;
  consommation_moyenne?: number;
  jours_restants?: number;
}

export default function PrevisionsStockPage() {
  const [produits, setProduits] = useState<ProduitAvecStock[]>([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState<'tous' | 'critique' | 'bas' | 'ok'>('tous');

  useEffect(() => {
    loadProduits();
  }, []);

  const loadProduits = async () => {
    try {
      setLoading(true);
      const data = await getProduits();
      // Simuler des données de stock pour la démo
      const produitsAvecStock = (data || []).map((p: Produit) => {
        const stockActuel = Math.floor(Math.random() * 500);
        const stockMin = Math.floor(Math.random() * 100) + 20;
        const consommation = Math.floor(Math.random() * 20) + 5;
        return {
          ...p,
          stock_actuel: stockActuel,
          stock_min: stockMin,
          consommation_moyenne: consommation,
          jours_restants: consommation > 0 ? Math.floor(stockActuel / consommation) : 999,
        };
      });
      setProduits(produitsAvecStock);
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  };

  const getStatut = (p: ProduitAvecStock) => {
    if (!p.stock_actuel || !p.stock_min) return 'ok';
    if (p.stock_actuel <= p.stock_min * 0.5) return 'critique';
    if (p.stock_actuel <= p.stock_min) return 'bas';
    return 'ok';
  };

  const filteredProduits = produits.filter(p => {
    if (filter === 'tous') return true;
    return getStatut(p) === filter;
  }).sort((a, b) => (a.jours_restants || 999) - (b.jours_restants || 999));

  const critiques = produits.filter(p => getStatut(p) === 'critique').length;
  const bas = produits.filter(p => getStatut(p) === 'bas').length;

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
              <p className="text-xl font-bold dark:text-white">{produits.length}</p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
          <div className="flex items-center gap-3">
            <AlertTriangle className="text-red-600" size={24} />
            <div>
              <p className="text-sm text-gray-500">Stock critique</p>
              <p className="text-xl font-bold text-red-600">{critiques}</p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
          <div className="flex items-center gap-3">
            <TrendingUp className="text-yellow-600" size={24} />
            <div>
              <p className="text-sm text-gray-500">Stock bas</p>
              <p className="text-xl font-bold text-yellow-600">{bas}</p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
          <div className="flex items-center gap-3">
            <ShoppingCart className="text-purple-600" size={24} />
            <div>
              <p className="text-sm text-gray-500">À commander</p>
              <p className="text-xl font-bold text-purple-600">{critiques + bas}</p>
            </div>
          </div>
        </div>
      </div>

      {/* Filtres */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 mb-6">
        <div className="flex gap-2">
          {[
            { key: 'tous', label: 'Tous', color: 'gray' },
            { key: 'critique', label: 'Critique', color: 'red' },
            { key: 'bas', label: 'Stock bas', color: 'yellow' },
            { key: 'ok', label: 'OK', color: 'green' },
          ].map(f => (
            <button
              key={f.key}
              onClick={() => setFilter(f.key as typeof filter)}
              className={`px-4 py-2 rounded-lg font-medium ${
                filter === f.key
                  ? f.color === 'red' ? 'bg-red-600 text-white'
                  : f.color === 'yellow' ? 'bg-yellow-500 text-white'
                  : f.color === 'green' ? 'bg-green-600 text-white'
                  : 'bg-gray-600 text-white'
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
          <div className="p-8 text-center text-gray-500">Chargement...</div>
        ) : filteredProduits.length === 0 ? (
          <div className="p-8 text-center text-gray-500">Aucun produit trouvé</div>
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
              {filteredProduits.map((produit) => {
                const statut = getStatut(produit);
                const suggestion = statut !== 'ok' 
                  ? Math.max(0, (produit.stock_min || 0) * 2 - (produit.stock_actuel || 0))
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
                      {produit.stock_actuel} {produit.mesure}
                    </td>
                    <td className="px-4 py-3 text-right text-sm text-gray-600 dark:text-gray-300">
                      {produit.stock_min} {produit.mesure}
                    </td>
                    <td className="px-4 py-3 text-right text-sm text-gray-600 dark:text-gray-300">
                      {produit.consommation_moyenne}
                    </td>
                    <td className="px-4 py-3 text-right text-sm">
                      <span className={
                        (produit.jours_restants || 0) <= 7 ? 'text-red-600 font-bold' :
                        (produit.jours_restants || 0) <= 14 ? 'text-yellow-600 font-medium' :
                        'text-gray-600 dark:text-gray-300'
                      }>
                        {produit.jours_restants} j
                      </span>
                    </td>
                    <td className="px-4 py-3 text-center">
                      <span className={`px-2 py-1 text-xs rounded-full ${
                        statut === 'critique' ? 'bg-red-100 text-red-800' :
                        statut === 'bas' ? 'bg-yellow-100 text-yellow-800' :
                        'bg-green-100 text-green-800'
                      }`}>
                        {statut === 'critique' ? 'Critique' : statut === 'bas' ? 'Bas' : 'OK'}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-right">
                      {suggestion > 0 && (
                        <span className="text-sm font-medium text-purple-600">
                          Commander {suggestion} {produit.mesure}
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
