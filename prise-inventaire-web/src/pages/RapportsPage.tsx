import { useEffect, useState } from 'react';
import { FileText, TrendingUp, TrendingDown, Users, Package, Calendar, Download } from 'lucide-react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer, LineChart, Line } from 'recharts';

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

interface SecteurRapport {
  secteur: string;
  entrants: { nombre: number; quantite: number };
  sortants: { nombre: number; quantite: number };
  solde: number;
}

interface EmployeRapport {
  employe: string;
  mouvements: number;
  scans: number;
  quantite_mouvements: number;
  quantite_scans: number;
}

interface EvolutionMois {
  mois: number;
  nom: string;
  mouvements: number;
  scans: number;
  quantite_mouvements: number;
  quantite_scans: number;
}

interface TopProduit {
  produit_numero: string;
  produit_nom: string | null;
  nombre_mouvements: number;
  quantite_totale: number;
}

const moisOptions = [
  { value: 1, label: 'Janvier' }, { value: 2, label: 'Février' }, { value: 3, label: 'Mars' },
  { value: 4, label: 'Avril' }, { value: 5, label: 'Mai' }, { value: 6, label: 'Juin' },
  { value: 7, label: 'Juillet' }, { value: 8, label: 'Août' }, { value: 9, label: 'Septembre' },
  { value: 10, label: 'Octobre' }, { value: 11, label: 'Novembre' }, { value: 12, label: 'Décembre' },
];

export default function RapportsPage() {
  const [mois, setMois] = useState(new Date().getMonth() + 1);
  const [annee, setAnnee] = useState(new Date().getFullYear());
  const [activeTab, setActiveTab] = useState<'secteurs' | 'employes' | 'evolution' | 'produits'>('secteurs');
  const [loading, setLoading] = useState(true);

  const [secteursData, setSecteursData] = useState<SecteurRapport[]>([]);
  const [employesData, setEmployesData] = useState<EmployeRapport[]>([]);
  const [evolutionData, setEvolutionData] = useState<EvolutionMois[]>([]);
  const [produitsData, setProduitsData] = useState<TopProduit[]>([]);
  const [totaux, setTotaux] = useState<{ entrants: { nombre: number; quantite: number }; sortants: { nombre: number; quantite: number } } | null>(null);

  useEffect(() => {
    loadData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [mois, annee, activeTab]);

  async function loadData() {
    setLoading(true);
    try {
      if (activeTab === 'secteurs') {
        const response = await fetch(`${API_BASE_URL}/rapports/mouvements-secteur?mois=${mois}&annee=${annee}`, {
          headers: getAuthHeaders(),
        });
        if (response.ok) {
          const data = await response.json();
          setSecteursData(data.rapport);
          setTotaux(data.totaux);
        }
      } else if (activeTab === 'employes') {
        const response = await fetch(`${API_BASE_URL}/rapports/activite-employe?mois=${mois}&annee=${annee}`, {
          headers: getAuthHeaders(),
        });
        if (response.ok) {
          const data = await response.json();
          setEmployesData(data.rapport);
        }
      } else if (activeTab === 'evolution') {
        const response = await fetch(`${API_BASE_URL}/rapports/evolution-annuelle?annee=${annee}`, {
          headers: getAuthHeaders(),
        });
        if (response.ok) {
          const data = await response.json();
          setEvolutionData(data.rapport);
        }
      } else if (activeTab === 'produits') {
        const response = await fetch(`${API_BASE_URL}/rapports/top-produits?mois=${mois}&annee=${annee}&limit=10`, {
          headers: getAuthHeaders(),
        });
        if (response.ok) {
          const data = await response.json();
          setProduitsData(data.produits);
        }
      }
    } catch (error) {
      console.error('Erreur chargement rapport:', error);
    } finally {
      setLoading(false);
    }
  }

  function exportCSV() {
    let csv = '';
    let filename = '';

    if (activeTab === 'secteurs') {
      csv = 'Secteur;Entrants (nb);Entrants (qté);Sortants (nb);Sortants (qté);Solde\n';
      secteursData.forEach(s => {
        csv += `${s.secteur};${s.entrants.nombre};${s.entrants.quantite};${s.sortants.nombre};${s.sortants.quantite};${s.solde}\n`;
      });
      filename = `rapport-secteurs-${mois}-${annee}.csv`;
    } else if (activeTab === 'employes') {
      csv = 'Employé;Mouvements;Scans;Qté Mouvements;Qté Scans\n';
      employesData.forEach(e => {
        csv += `${e.employe};${e.mouvements};${e.scans};${e.quantite_mouvements};${e.quantite_scans}\n`;
      });
      filename = `rapport-employes-${mois}-${annee}.csv`;
    } else if (activeTab === 'produits') {
      csv = 'Produit;Nom;Mouvements;Quantité\n';
      produitsData.forEach(p => {
        csv += `${p.produit_numero};${p.produit_nom || ''};${p.nombre_mouvements};${p.quantite_totale}\n`;
      });
      filename = `rapport-produits-${mois}-${annee}.csv`;
    }

    if (csv) {
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = filename;
      link.click();
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-800">Rapports</h1>
          <p className="text-gray-500">Rapports mensuels des mouvements</p>
        </div>
        <button
          onClick={exportCSV}
          className="flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
        >
          <Download size={18} />
          Exporter CSV
        </button>
      </div>

      {/* Filtres */}
      <div className="bg-white rounded-xl shadow-sm p-4">
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex items-center gap-2">
            <Calendar size={18} className="text-gray-400" />
            <select
              value={mois}
              onChange={(e) => setMois(parseInt(e.target.value))}
              className="px-3 py-2 border rounded-lg"
            >
              {moisOptions.map(m => (
                <option key={m.value} value={m.value}>{m.label}</option>
              ))}
            </select>
            <select
              value={annee}
              onChange={(e) => setAnnee(parseInt(e.target.value))}
              className="px-3 py-2 border rounded-lg"
            >
              {[2024, 2025, 2026].map(a => (
                <option key={a} value={a}>{a}</option>
              ))}
            </select>
          </div>
        </div>
      </div>

      {/* Onglets */}
      <div className="flex gap-2 border-b">
        {[
          { id: 'secteurs', label: 'Par Secteur', icon: FileText },
          { id: 'employes', label: 'Par Employé', icon: Users },
          { id: 'evolution', label: 'Évolution Annuelle', icon: TrendingUp },
          { id: 'produits', label: 'Top Produits', icon: Package },
        ].map(tab => (
          <button
            key={tab.id}
            onClick={() => setActiveTab(tab.id as typeof activeTab)}
            className={`flex items-center gap-2 px-4 py-3 border-b-2 transition-colors ${
              activeTab === tab.id
                ? 'border-blue-600 text-blue-600'
                : 'border-transparent text-gray-500 hover:text-gray-700'
            }`}
          >
            <tab.icon size={18} />
            {tab.label}
          </button>
        ))}
      </div>

      {loading ? (
        <div className="bg-white rounded-xl shadow-sm p-8 text-center text-gray-500">
          Chargement...
        </div>
      ) : (
        <>
          {/* Rapport par secteur */}
          {activeTab === 'secteurs' && (
            <div className="space-y-6">
              {/* Totaux */}
              {totaux && (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className="bg-white rounded-xl shadow-sm p-5">
                    <div className="flex items-center gap-3">
                      <div className="p-3 bg-green-100 rounded-lg">
                        <TrendingUp className="text-green-600" size={24} />
                      </div>
                      <div>
                        <p className="text-2xl font-bold text-green-600">{totaux.entrants.quantite.toLocaleString()}</p>
                        <p className="text-sm text-gray-500">{totaux.entrants.nombre} mouvements entrants</p>
                      </div>
                    </div>
                  </div>
                  <div className="bg-white rounded-xl shadow-sm p-5">
                    <div className="flex items-center gap-3">
                      <div className="p-3 bg-red-100 rounded-lg">
                        <TrendingDown className="text-red-600" size={24} />
                      </div>
                      <div>
                        <p className="text-2xl font-bold text-red-600">{totaux.sortants.quantite.toLocaleString()}</p>
                        <p className="text-sm text-gray-500">{totaux.sortants.nombre} mouvements sortants</p>
                      </div>
                    </div>
                  </div>
                </div>
              )}

              {/* Graphique */}
              {secteursData.length > 0 && (
                <div className="bg-white rounded-xl shadow-sm p-6">
                  <h3 className="text-lg font-semibold mb-4">Mouvements par secteur</h3>
                  <ResponsiveContainer width="100%" height={300}>
                    <BarChart data={secteursData}>
                      <CartesianGrid strokeDasharray="3 3" />
                      <XAxis dataKey="secteur" />
                      <YAxis />
                      <Tooltip />
                      <Legend />
                      <Bar dataKey="entrants.quantite" name="Entrants" fill="#22c55e" />
                      <Bar dataKey="sortants.quantite" name="Sortants" fill="#ef4444" />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              )}

              {/* Tableau */}
              <div className="bg-white rounded-xl shadow-sm overflow-hidden">
                <table className="w-full">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-4 py-3 text-left text-sm font-medium text-gray-700">Secteur</th>
                      <th className="px-4 py-3 text-right text-sm font-medium text-gray-700">Entrants (nb)</th>
                      <th className="px-4 py-3 text-right text-sm font-medium text-gray-700">Entrants (qté)</th>
                      <th className="px-4 py-3 text-right text-sm font-medium text-gray-700">Sortants (nb)</th>
                      <th className="px-4 py-3 text-right text-sm font-medium text-gray-700">Sortants (qté)</th>
                      <th className="px-4 py-3 text-right text-sm font-medium text-gray-700">Solde</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y">
                    {secteursData.map(s => (
                      <tr key={s.secteur} className="hover:bg-gray-50">
                        <td className="px-4 py-3 font-medium">{s.secteur}</td>
                        <td className="px-4 py-3 text-right text-green-600">{s.entrants.nombre}</td>
                        <td className="px-4 py-3 text-right text-green-600">{s.entrants.quantite.toLocaleString()}</td>
                        <td className="px-4 py-3 text-right text-red-600">{s.sortants.nombre}</td>
                        <td className="px-4 py-3 text-right text-red-600">{s.sortants.quantite.toLocaleString()}</td>
                        <td className={`px-4 py-3 text-right font-bold ${s.solde >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                          {s.solde >= 0 ? '+' : ''}{s.solde.toLocaleString()}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* Rapport par employé */}
          {activeTab === 'employes' && (
            <div className="bg-white rounded-xl shadow-sm overflow-hidden">
              <table className="w-full">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-sm font-medium text-gray-700">Employé</th>
                    <th className="px-4 py-3 text-right text-sm font-medium text-gray-700">Mouvements</th>
                    <th className="px-4 py-3 text-right text-sm font-medium text-gray-700">Scans</th>
                    <th className="px-4 py-3 text-right text-sm font-medium text-gray-700">Qté Mouvements</th>
                    <th className="px-4 py-3 text-right text-sm font-medium text-gray-700">Qté Scans</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {employesData.map(e => (
                    <tr key={e.employe} className="hover:bg-gray-50">
                      <td className="px-4 py-3 font-medium">{e.employe}</td>
                      <td className="px-4 py-3 text-right">{e.mouvements}</td>
                      <td className="px-4 py-3 text-right">{e.scans}</td>
                      <td className="px-4 py-3 text-right">{e.quantite_mouvements.toLocaleString()}</td>
                      <td className="px-4 py-3 text-right">{e.quantite_scans.toLocaleString()}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {/* Évolution annuelle */}
          {activeTab === 'evolution' && evolutionData.length > 0 && (
            <div className="bg-white rounded-xl shadow-sm p-6">
              <h3 className="text-lg font-semibold mb-4">Évolution {annee}</h3>
              <ResponsiveContainer width="100%" height={400}>
                <LineChart data={evolutionData}>
                  <CartesianGrid strokeDasharray="3 3" />
                  <XAxis dataKey="nom" />
                  <YAxis />
                  <Tooltip />
                  <Legend />
                  <Line type="monotone" dataKey="mouvements" name="Mouvements" stroke="#3b82f6" strokeWidth={2} />
                  <Line type="monotone" dataKey="scans" name="Scans" stroke="#8b5cf6" strokeWidth={2} />
                </LineChart>
              </ResponsiveContainer>
            </div>
          )}

          {/* Top produits */}
          {activeTab === 'produits' && (
            <div className="bg-white rounded-xl shadow-sm overflow-hidden">
              <table className="w-full">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-sm font-medium text-gray-700">#</th>
                    <th className="px-4 py-3 text-left text-sm font-medium text-gray-700">Produit</th>
                    <th className="px-4 py-3 text-left text-sm font-medium text-gray-700">Nom</th>
                    <th className="px-4 py-3 text-right text-sm font-medium text-gray-700">Mouvements</th>
                    <th className="px-4 py-3 text-right text-sm font-medium text-gray-700">Quantité</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {produitsData.map((p, index) => (
                    <tr key={p.produit_numero} className="hover:bg-gray-50">
                      <td className="px-4 py-3 text-gray-500">{index + 1}</td>
                      <td className="px-4 py-3 font-medium">{p.produit_numero}</td>
                      <td className="px-4 py-3 text-gray-600">{p.produit_nom || '-'}</td>
                      <td className="px-4 py-3 text-right">{p.nombre_mouvements}</td>
                      <td className="px-4 py-3 text-right font-bold">{p.quantite_totale.toLocaleString()}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </div>
  );
}
