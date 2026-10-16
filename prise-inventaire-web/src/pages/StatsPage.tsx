import { useEffect, useState } from 'react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, LineChart, Line, PieChart, Pie, Cell } from 'recharts';
import { TrendingUp, Calendar, Package, Users } from 'lucide-react';
import { getStatistiquesScans, type StatistiquesScans } from '@/services/api';
import { derniersJours, fuseauNavigateur, jourLocal } from '@/lib/periodes';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';
import PageSkeleton from '@/components/PageSkeleton';

const COLORS = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#06B6D4', '#84CC16'];

export default function StatsPage() {
  const { toasts, toast, dismiss } = useToast();
  const [stats, setStats] = useState<StatistiquesScans | null>(null);
  const [loading, setLoading] = useState(true);
  const [periode, setPeriode] = useState<'7' | '30' | '90'>('30');

  // Statistiques calculées par l'API sur tous les scans de la période (et non sur les 100 derniers), jours
  // regroupés dans le fuseau du navigateur.
  useEffect(() => {
    async function loadData() {
      setLoading(true);
      try {
        setStats(await getStatistiquesScans({ ...derniersJours(parseInt(periode)), fuseau: fuseauNavigateur() }));
      } catch {
        toast('Erreur de chargement des données', 'error');
      } finally {
        setLoading(false);
      }
    }
    loadData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [periode]);

  const scansByDay = (stats?.par_jour ?? []).map(j => ({ date: j.date, count: j.total }));
  const scansBySecteur = (stats?.par_secteur ?? []).map(s => ({ name: s.nom, value: s.total }));
  const scansByEmploye = (stats?.par_employe ?? []).map(e => ({ name: e.nom, value: e.total }));
  const scansByType = (stats?.par_type ?? []).map(t => ({ name: t.nom, value: t.total }));

  const totalScans = stats?.total ?? 0;
  const uniqueSecteurs = stats?.secteurs_distincts ?? 0;
  const uniqueEmployes = stats?.employes_distincts ?? 0;
  const avgPerDay = stats?.moyenne_par_jour ?? 0;

  if (loading) return <PageSkeleton kpis={4} rows={4} />;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-gray-800">Statistiques Inventaire</h1>
        <div className="flex items-center gap-2">
          <Calendar size={18} className="text-gray-400" />
          <select
            value={periode}
            onChange={(e) => setPeriode(e.target.value as '7' | '30' | '90')}
            className="border rounded-lg px-3 py-2 text-sm"
          >
            <option value="7">7 derniers jours</option>
            <option value="30">30 derniers jours</option>
            <option value="90">90 derniers jours</option>
          </select>
        </div>
      </div>

      {/* KPIs */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="bg-white rounded-xl shadow-sm p-5">
          <div className="flex items-center gap-3">
            <div className="p-3 bg-blue-100 rounded-lg">
              <TrendingUp className="text-blue-600" size={24} />
            </div>
            <div>
              <p className="text-2xl font-bold text-gray-800">{totalScans}</p>
              <p className="text-sm text-gray-500">Total scans</p>
            </div>
          </div>
        </div>
        <div className="bg-white rounded-xl shadow-sm p-5">
          <div className="flex items-center gap-3">
            <div className="p-3 bg-green-100 rounded-lg">
              <Package className="text-green-600" size={24} />
            </div>
            <div>
              <p className="text-2xl font-bold text-gray-800">{avgPerDay}</p>
              <p className="text-sm text-gray-500">Moyenne/jour</p>
            </div>
          </div>
        </div>
        <div className="bg-white rounded-xl shadow-sm p-5">
          <div className="flex items-center gap-3">
            <div className="p-3 bg-purple-100 rounded-lg">
              <Package className="text-purple-600" size={24} />
            </div>
            <div>
              <p className="text-2xl font-bold text-gray-800">{uniqueSecteurs}</p>
              <p className="text-sm text-gray-500">Secteurs actifs</p>
            </div>
          </div>
        </div>
        <div className="bg-white rounded-xl shadow-sm p-5">
          <div className="flex items-center gap-3">
            <div className="p-3 bg-orange-100 rounded-lg">
              <Users className="text-orange-600" size={24} />
            </div>
            <div>
              <p className="text-2xl font-bold text-gray-800">{uniqueEmployes}</p>
              <p className="text-sm text-gray-500">Employés actifs</p>
            </div>
          </div>
        </div>
      </div>

      {/* Graphique évolution */}
      <div className="bg-white rounded-xl shadow-sm p-6">
        <h2 className="text-lg font-semibold text-gray-800 mb-4">Évolution des scans</h2>
        {scansByDay.length > 0 ? (
          <ResponsiveContainer width="100%" height={300}>
            <LineChart data={scansByDay}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis 
                dataKey="date" 
                tickFormatter={(val) => jourLocal(val).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' })}
              />
              <YAxis />
              <Tooltip 
                labelFormatter={(val) => jourLocal(String(val)).toLocaleDateString('fr-FR')}
                formatter={(value) => [value ?? 0, 'Scans']}
              />
              <Line type="monotone" dataKey="count" stroke="#3B82F6" strokeWidth={2} dot={{ fill: '#3B82F6' }} />
            </LineChart>
          </ResponsiveContainer>
        ) : (
          <div className="h-[300px] flex items-center justify-center text-gray-400">
            Aucune donnée pour cette période
          </div>
        )}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Par secteur */}
        <div className="bg-white rounded-xl shadow-sm p-6">
          <h2 className="text-lg font-semibold text-gray-800 mb-4">Scans par secteur</h2>
          {scansBySecteur.length > 0 ? (
            <ResponsiveContainer width="100%" height={300}>
              <BarChart data={scansBySecteur} layout="vertical">
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis type="number" />
                <YAxis dataKey="name" type="category" width={60} />
                <Tooltip />
                <Bar dataKey="value" fill="#8B5CF6" radius={[0, 4, 4, 0]} />
              </BarChart>
            </ResponsiveContainer>
          ) : (
            <div className="h-[300px] flex items-center justify-center text-gray-400">
              Aucune donnée
            </div>
          )}
        </div>

        {/* Par type */}
        <div className="bg-white rounded-xl shadow-sm p-6">
          <h2 className="text-lg font-semibold text-gray-800 mb-4">Répartition par type</h2>
          {scansByType.length > 0 ? (
            <ResponsiveContainer width="100%" height={300}>
              <PieChart>
                <Pie
                  data={scansByType}
                  cx="50%"
                  cy="50%"
                  labelLine={false}
                  label={({ name, percent }) => `${name} (${((percent ?? 0) * 100).toFixed(0)}%)`}
                  outerRadius={100}
                  fill="#8884d8"
                  dataKey="value"
                >
                  {scansByType.map((_, index) => (
                    <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                  ))}
                </Pie>
                <Tooltip />
              </PieChart>
            </ResponsiveContainer>
          ) : (
            <div className="h-[300px] flex items-center justify-center text-gray-400">
              Aucune donnée
            </div>
          )}
        </div>
      </div>

      {/* Top employés */}
      <div className="bg-white rounded-xl shadow-sm p-6">
        <h2 className="text-lg font-semibold text-gray-800 mb-4">Top 10 employés</h2>
        {scansByEmploye.length > 0 ? (
          <ResponsiveContainer width="100%" height={300}>
            <BarChart data={scansByEmploye}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="name" />
              <YAxis />
              <Tooltip />
              <Bar dataKey="value" fill="#10B981" radius={[4, 4, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        ) : (
          <div className="h-[300px] flex items-center justify-center text-gray-400">
            Aucune donnée
          </div>
        )}
      </div>
      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}
