import { useEffect, useState, useMemo } from 'react';
import { ArrowUp, ArrowDown, Minus, Calendar, Download, RefreshCw } from 'lucide-react';
import { getBornesScans, getComparaisonScans } from '@/services/api';
import { bornesPeriode, dateLocale, jourLocal } from '@/lib/periodes';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';
import PageSkeleton from '@/components/PageSkeleton';
import EmptyState from '@/components/EmptyState';

interface ProduitComparaison {
  numero: string;
  type: string;
  quantiteActuelle: number;
  quantitePrecedente: number;
  difference: number;
  pourcentage: number;
  secteur: string;
}

export default function ComparaisonPage() {
  const { toasts, toast, dismiss } = useToast();
  const [comparaison, setComparaison] = useState<ProduitComparaison[]>([]);
  const [loading, setLoading] = useState(true);
  const [dateDebut, setDateDebut] = useState('');
  const [dateFin, setDateFin] = useState('');
  const [filterDiff, setFilterDiff] = useState<'all' | 'up' | 'down' | 'same'>('all');

  // Dates proposées : jour du premier et du dernier scan, dans le fuseau du navigateur.
  useEffect(() => {
    getBornesScans()
      .then(bornes => {
        if (bornes.premier) setDateDebut(dateLocale(bornes.premier));
        if (bornes.dernier) setDateFin(dateLocale(bornes.dernier));
        if (!bornes.premier) setLoading(false);
      })
      .catch(() => {
        toast('Erreur de chargement des données', 'error');
        setLoading(false);
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Quantités comptées à chacune des deux dates, calculées par l'API sur tout l'historique. La page
  // additionnait tous les scans jusqu'à chaque date (sur les 100 derniers) : un cumul ne baisse jamais.
  useEffect(() => {
    if (!dateDebut || !dateFin) return;
    const a = bornesPeriode(dateDebut, dateDebut);
    const b = bornesPeriode(dateFin, dateFin);
    setLoading(true);
    getComparaisonScans({ depuis: a.depuis!, jusqu_a: a.jusqu_a! }, { depuis: b.depuis!, jusqu_a: b.jusqu_a! })
      .then(({ lignes }) => setComparaison(lignes.map(l => ({
        numero: l.numero,
        type: l.type || '',
        secteur: l.secteur || '',
        quantitePrecedente: l.quantite_a,
        quantiteActuelle: l.quantite_b,
        difference: l.difference,
        pourcentage: l.pourcentage,
      }))))
      .catch(() => toast('Erreur de chargement des données', 'error'))
      .finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dateDebut, dateFin]);

  const jourAffiche = (date: string) => (date ? jourLocal(date).toLocaleDateString('fr-FR') : '-');

  // Filtrer selon le type de différence
  const filteredComparaison = useMemo(() => {
    switch (filterDiff) {
      case 'up':
        return comparaison.filter(c => c.difference > 0);
      case 'down':
        return comparaison.filter(c => c.difference < 0);
      case 'same':
        return comparaison.filter(c => c.difference === 0);
      default:
        return comparaison;
    }
  }, [comparaison, filterDiff]);

  // Stats
  const stats = useMemo(() => {
    const augmentations = comparaison.filter(c => c.difference > 0).length;
    const diminutions = comparaison.filter(c => c.difference < 0).length;
    const stables = comparaison.filter(c => c.difference === 0).length;
    return { augmentations, diminutions, stables, total: comparaison.length };
  }, [comparaison]);

  function exportToCSV() {
    const headers = ['Numéro', 'Type', 'Secteur', 'Qté Précédente', 'Qté Actuelle', 'Différence', 'Variation %'];
    const csvContent = [
      headers.join(';'),
      ...filteredComparaison.map(c => [
        c.numero,
        c.type,
        c.secteur,
        c.quantitePrecedente,
        c.quantiteActuelle,
        c.difference,
        `${c.pourcentage}%`
      ].join(';'))
    ].join('\n');

    const blob = new Blob(['\ufeff' + csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `comparaison_${dateDebut}_${dateFin}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  }

  if (loading) return <PageSkeleton kpis={3} />;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-800">Comparaison d'Inventaire</h1>
          <p className="text-gray-500">Comparez, produit par produit, les quantités comptées à deux dates</p>
        </div>
        <button
          onClick={exportToCSV}
          disabled={filteredComparaison.length === 0}
          className="flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50"
        >
          <Download size={18} />
          Exporter
        </button>
      </div>

      {/* Sélection des dates */}
      <div className="bg-white rounded-xl shadow-sm p-4">
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex items-center gap-2">
            <Calendar size={20} className="text-gray-400" />
            <span className="font-medium text-gray-700">Dates comparées</span>
          </div>
          <div className="flex items-center gap-2">
            <label htmlFor="comparaison-date-a" className="text-sm text-gray-500">Première date :</label>
            <input
              id="comparaison-date-a"
              type="date"
              value={dateDebut}
              onChange={(e) => setDateDebut(e.target.value)}
              className="border rounded-lg px-3 py-2 text-sm"
            />
          </div>
          <div className="flex items-center gap-2">
            <label htmlFor="comparaison-date-b" className="text-sm text-gray-500">Seconde date :</label>
            <input
              id="comparaison-date-b"
              type="date"
              value={dateFin}
              onChange={(e) => setDateFin(e.target.value)}
              className="border rounded-lg px-3 py-2 text-sm"
            />
          </div>
        </div>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div
          className={`bg-white rounded-xl shadow-sm p-5 cursor-pointer transition-all ${filterDiff === 'all' ? 'ring-2 ring-blue-500' : ''}`}
          onClick={() => setFilterDiff('all')}
        >
          <div className="flex items-center gap-3">
            <div className="p-3 bg-gray-100 rounded-lg">
              <RefreshCw className="text-gray-600" size={24} />
            </div>
            <div>
              <p className="text-2xl font-bold text-gray-800">{stats.total}</p>
              <p className="text-sm text-gray-500">Total produits</p>
            </div>
          </div>
        </div>
        <div
          className={`bg-white rounded-xl shadow-sm p-5 cursor-pointer transition-all ${filterDiff === 'up' ? 'ring-2 ring-green-500' : ''}`}
          onClick={() => setFilterDiff(filterDiff === 'up' ? 'all' : 'up')}
        >
          <div className="flex items-center gap-3">
            <div className="p-3 bg-green-100 rounded-lg">
              <ArrowUp className="text-green-600" size={24} />
            </div>
            <div>
              <p className="text-2xl font-bold text-green-600">{stats.augmentations}</p>
              <p className="text-sm text-gray-500">Augmentations</p>
            </div>
          </div>
        </div>
        <div
          className={`bg-white rounded-xl shadow-sm p-5 cursor-pointer transition-all ${filterDiff === 'down' ? 'ring-2 ring-red-500' : ''}`}
          onClick={() => setFilterDiff(filterDiff === 'down' ? 'all' : 'down')}
        >
          <div className="flex items-center gap-3">
            <div className="p-3 bg-red-100 rounded-lg">
              <ArrowDown className="text-red-600" size={24} />
            </div>
            <div>
              <p className="text-2xl font-bold text-red-600">{stats.diminutions}</p>
              <p className="text-sm text-gray-500">Diminutions</p>
            </div>
          </div>
        </div>
        <div
          className={`bg-white rounded-xl shadow-sm p-5 cursor-pointer transition-all ${filterDiff === 'same' ? 'ring-2 ring-gray-500' : ''}`}
          onClick={() => setFilterDiff(filterDiff === 'same' ? 'all' : 'same')}
        >
          <div className="flex items-center gap-3">
            <div className="p-3 bg-gray-100 rounded-lg">
              <Minus className="text-gray-600" size={24} />
            </div>
            <div>
              <p className="text-2xl font-bold text-gray-600">{stats.stables}</p>
              <p className="text-sm text-gray-500">Stables</p>
            </div>
          </div>
        </div>
      </div>

      {/* Table de comparaison */}
      <div className="bg-white rounded-xl shadow-sm overflow-hidden">
        {filteredComparaison.length === 0 ? (
          <EmptyState icon="📊" title="Aucune donnée à comparer pour cette période" />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 border-b">
                <tr>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Produit</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Type</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Secteur</th>
                  <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">
                    Qté le {jourAffiche(dateDebut)}
                  </th>
                  <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">
                    Qté le {jourAffiche(dateFin)}
                  </th>
                  <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">Différence</th>
                  <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">Variation</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {filteredComparaison.map((item) => (
                  <tr key={item.numero} className="hover:bg-gray-50">
                    <td className="px-4 py-3 text-sm font-mono font-medium">{item.numero}</td>
                    <td className="px-4 py-3 text-sm text-gray-600">{item.type}</td>
                    <td className="px-4 py-3 text-sm">
                      <span className="bg-purple-100 text-purple-700 px-2 py-1 rounded text-xs font-medium">
                        {item.secteur}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-sm text-right text-gray-500">
                      {item.quantitePrecedente}
                    </td>
                    <td className="px-4 py-3 text-sm text-right font-medium">
                      {item.quantiteActuelle}
                    </td>
                    <td className="px-4 py-3 text-sm text-right font-medium">
                      <span className={`flex items-center justify-end gap-1 ${
                        item.difference > 0 ? 'text-green-600' :
                        item.difference < 0 ? 'text-red-600' : 'text-gray-500'
                      }`}>
                        {item.difference > 0 && <ArrowUp size={14} />}
                        {item.difference < 0 && <ArrowDown size={14} />}
                        {item.difference === 0 && <Minus size={14} />}
                        {item.difference > 0 ? '+' : ''}{item.difference}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-sm text-right">
                      <span className={`px-2 py-1 rounded text-xs font-medium ${
                        item.pourcentage > 0 ? 'bg-green-100 text-green-700' :
                        item.pourcentage < 0 ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600'
                      }`}>
                        {item.pourcentage > 0 ? '+' : ''}{item.pourcentage}%
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}
