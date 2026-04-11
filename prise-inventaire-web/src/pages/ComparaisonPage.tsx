import { useEffect, useState, useMemo } from 'react';
import { ArrowUp, ArrowDown, Minus, Calendar, Download, RefreshCw } from 'lucide-react';
import { getScans } from '@/services/api';
import type { InventaireScan } from '@/types';
import { formatDate } from '@/lib/utils';
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
  const [scans, setScans] = useState<InventaireScan[]>([]);
  const [loading, setLoading] = useState(true);
  const [dateDebut, setDateDebut] = useState('');
  const [dateFin, setDateFin] = useState('');
  const [filterDiff, setFilterDiff] = useState<'all' | 'up' | 'down' | 'same'>('all');

  useEffect(() => {
    async function loadData() {
      setLoading(true);
      try {
        const data = await getScans();
        setScans(data);
        
        // Définir les dates par défaut
        if (data.length > 0) {
          const dates = data.map(s => new Date(s.date_saisie).toISOString().split('T')[0]);
          const uniqueDates = [...new Set(dates)].sort();
          if (uniqueDates.length >= 2) {
            setDateDebut(uniqueDates[0]);
            setDateFin(uniqueDates[uniqueDates.length - 1]);
          } else if (uniqueDates.length === 1) {
            setDateDebut(uniqueDates[0]);
            setDateFin(uniqueDates[0]);
          }
        }
      } catch (error) {
        toast('Erreur de chargement des données', 'error');
      } finally {
        setLoading(false);
      }
    }
    loadData();
  }, []);

  // Calculer la comparaison
  const comparaison = useMemo(() => {
    if (!dateDebut || !dateFin) return [];

    // Scans de la période de référence (début)
    const scansPrecedents = scans.filter(s => {
      const date = new Date(s.date_saisie).toISOString().split('T')[0];
      return date <= dateDebut;
    });

    // Scans de la période actuelle (fin)
    const scansActuels = scans.filter(s => {
      const date = new Date(s.date_saisie).toISOString().split('T')[0];
      return date <= dateFin;
    });

    // Grouper par produit
    const groupByProduit = (items: InventaireScan[]) => {
      const grouped: Record<string, { quantite: number; type: string; secteur: string }> = {};
      items.forEach(scan => {
        if (!grouped[scan.numero]) {
          grouped[scan.numero] = { quantite: 0, type: scan.type || '', secteur: scan.secteur };
        }
        grouped[scan.numero].quantite += parseFloat(String(scan.quantite));
      });
      return grouped;
    };

    const precedents = groupByProduit(scansPrecedents);
    const actuels = groupByProduit(scansActuels);

    // Créer la liste de comparaison
    const allNumeros = new Set([...Object.keys(precedents), ...Object.keys(actuels)]);
    const result: ProduitComparaison[] = [];

    allNumeros.forEach(numero => {
      const qteActuelle = actuels[numero]?.quantite || 0;
      const qtePrecedente = precedents[numero]?.quantite || 0;
      const diff = qteActuelle - qtePrecedente;
      const pct = qtePrecedente > 0 ? ((diff / qtePrecedente) * 100) : (qteActuelle > 0 ? 100 : 0);

      result.push({
        numero,
        type: actuels[numero]?.type || precedents[numero]?.type || '',
        quantiteActuelle: qteActuelle,
        quantitePrecedente: qtePrecedente,
        difference: diff,
        pourcentage: Math.round(pct * 10) / 10,
        secteur: actuels[numero]?.secteur || precedents[numero]?.secteur || '',
      });
    });

    // Trier par différence absolue décroissante
    return result.sort((a, b) => Math.abs(b.difference) - Math.abs(a.difference));
  }, [scans, dateDebut, dateFin]);

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
          <p className="text-gray-500">Comparez l'évolution des stocks entre deux périodes</p>
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
            <span className="font-medium text-gray-700">Période de comparaison</span>
          </div>
          <div className="flex items-center gap-2">
            <label className="text-sm text-gray-500">Du:</label>
            <input
              type="date"
              value={dateDebut}
              onChange={(e) => setDateDebut(e.target.value)}
              className="border rounded-lg px-3 py-2 text-sm"
            />
          </div>
          <div className="flex items-center gap-2">
            <label className="text-sm text-gray-500">Au:</label>
            <input
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
                    Qté au {dateDebut ? formatDate(dateDebut) : '-'}
                  </th>
                  <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">
                    Qté au {dateFin ? formatDate(dateFin) : '-'}
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
