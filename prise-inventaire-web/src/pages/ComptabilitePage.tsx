import { useState, useEffect, useCallback, useMemo } from 'react';
import { Download, FileSpreadsheet, TrendingUp, TrendingDown, BarChart2 } from 'lucide-react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import { getFactures, getReceptions } from '../services/api';
import type { Facture } from '../services/api';

interface ReceptionLigne {
  id: number;
  date_reception: string;
  quantite_recue: number;
  ligne_commande?: {
    prix_unitaire?: number;
    commande?: {
      numero: string;
      fournisseur?: {
        raison_sociale: string;
      };
    };
  };
}

function fmtDate(d: string): string {
  return new Date(d).toLocaleDateString('fr-FR');
}

function getMontantReception(r: ReceptionLigne): number {
  return (r.quantite_recue || 0) * (r.ligne_commande?.prix_unitaire || 0);
}

export default function ComptabilitePage() {
  const [activeTab, setActiveTab] = useState<'ventes' | 'achats'>('ventes');
  const [factures, setFactures] = useState<Facture[]>([]);
  const [receptions, setReceptions] = useState<ReceptionLigne[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterFournisseur, setFilterFournisseur] = useState('');
  const [groupByCommande, setGroupByCommande] = useState(false);
  const [dateDebut, setDateDebut] = useState(() => {
    const d = new Date();
    d.setMonth(d.getMonth() - 1);
    return d.toISOString().split('T')[0];
  });
  const [dateFin, setDateFin] = useState(new Date().toISOString().split('T')[0]);

  const loadData = useCallback(async () => {
    try {
      setLoading(true);
      const [facturesResponse, receptionsResponse] = await Promise.all([
        getFactures({}),
        getReceptions(),
      ]);

      const filteredFactures = (facturesResponse.data || []).filter((f: Facture) => {
        if (f.statut === 'brouillon') return false;
        const date = new Date(f.date_facture);
        return date >= new Date(dateDebut) && date <= new Date(dateFin);
      });
      setFactures(filteredFactures);

      const receptionsData = Array.isArray(receptionsResponse) ? receptionsResponse : (receptionsResponse?.data || []);
      const filteredReceptions = receptionsData.filter((r: ReceptionLigne) => {
        const date = new Date(r.date_reception);
        return date >= new Date(dateDebut) && date <= new Date(dateFin);
      });
      setReceptions(filteredReceptions);
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }, [dateDebut, dateFin]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const totalVentes = factures.reduce((sum, f) => sum + (f.montant_ht || 0), 0);
  const totalAchats = receptions.reduce((sum, r) => sum + getMontantReception(r), 0);
  const marge = totalVentes - totalAchats;

  // Liste des fournisseurs uniques pour le filtre
  const fournisseurs = useMemo(() => {
    const set = new Set<string>();
    receptions.forEach(r => {
      const f = r.ligne_commande?.commande?.fournisseur?.raison_sociale;
      if (f) set.add(f);
    });
    return Array.from(set).sort();
  }, [receptions]);

  // Réceptions filtrées par fournisseur
  const receptionsFiltrees = useMemo(() => {
    if (!filterFournisseur) return receptions;
    return receptions.filter(r =>
      r.ligne_commande?.commande?.fournisseur?.raison_sociale === filterFournisseur
    );
  }, [receptions, filterFournisseur]);

  // Regroupement par commande
  const achatsGroupes = useMemo(() => {
    if (!groupByCommande) return null;
    const map = new Map<string, { numero: string; fournisseur: string; date: string; total: number; lignes: number }>();
    receptionsFiltrees.forEach(r => {
      const numero = r.ligne_commande?.commande?.numero || '—';
      const existing = map.get(numero);
      const montant = getMontantReception(r);
      if (existing) {
        existing.total += montant;
        existing.lignes += 1;
      } else {
        map.set(numero, {
          numero,
          fournisseur: r.ligne_commande?.commande?.fournisseur?.raison_sociale || '—',
          date: r.date_reception,
          total: montant,
          lignes: 1,
        });
      }
    });
    return Array.from(map.values());
  }, [receptionsFiltrees, groupByCommande]);

  // Données graphique mensuel
  const chartData = useMemo(() => {
    const map = new Map<string, { mois: string; ventes: number; achats: number }>();

    factures.forEach(f => {
      const d = new Date(f.date_facture);
      const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
      const label = d.toLocaleDateString('fr-FR', { month: 'short', year: '2-digit' });
      const existing = map.get(key) || { mois: label, ventes: 0, achats: 0 };
      existing.ventes += f.montant_ht || 0;
      map.set(key, existing);
    });

    receptions.forEach(r => {
      const d = new Date(r.date_reception);
      const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
      const label = d.toLocaleDateString('fr-FR', { month: 'short', year: '2-digit' });
      const existing = map.get(key) || { mois: label, ventes: 0, achats: 0 };
      existing.achats += getMontantReception(r);
      map.set(key, existing);
    });

    return Array.from(map.entries())
      .sort(([a], [b]) => a.localeCompare(b))
      .map(([, v]) => v);
  }, [factures, receptions]);

  const exportCSV = () => {
    let csv = '';
    if (activeTab === 'ventes') {
      csv = 'Date;Numéro;Client;Montant\n';
      factures.forEach(f => {
        csv += `${f.date_facture};${f.numero};${f.client?.raison_sociale || ''};${f.montant_ht?.toFixed(2)}\n`;
      });
    } else {
      csv = 'Date;Commande;Fournisseur;Quantité;Montant\n';
      receptionsFiltrees.forEach(r => {
        const montant = getMontantReception(r);
        csv += `${r.date_reception};${r.ligne_commande?.commande?.numero || ''};${r.ligne_commande?.commande?.fournisseur?.raison_sociale || ''};${r.quantite_recue};${montant.toFixed(2)}\n`;
      });
    }

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `journal_${activeTab}_${dateDebut}_${dateFin}.csv`;
    link.click();
  };

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Comptabilité</h1>
        <button
          onClick={exportCSV}
          className="flex items-center gap-2 bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700"
        >
          <Download size={20} />
          Exporter CSV
        </button>
      </div>

      {/* Cartes résumé */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
          <div className="flex items-center gap-3">
            <div className="p-3 bg-green-100 dark:bg-green-900 rounded-lg">
              <TrendingUp className="text-green-600 dark:text-green-400" size={24} />
            </div>
            <div>
              <p className="text-sm text-gray-500 dark:text-gray-400">Total Ventes</p>
              <p className="text-2xl font-bold text-green-600">{totalVentes.toFixed(2)} €</p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
          <div className="flex items-center gap-3">
            <div className="p-3 bg-red-100 dark:bg-red-900 rounded-lg">
              <TrendingDown className="text-red-600 dark:text-red-400" size={24} />
            </div>
            <div>
              <p className="text-sm text-gray-500 dark:text-gray-400">Total Achats</p>
              <p className="text-2xl font-bold text-red-600">{totalAchats.toFixed(2)} €</p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
          <div className="flex items-center gap-3">
            <div className={`p-3 rounded-lg ${marge >= 0 ? 'bg-blue-100 dark:bg-blue-900' : 'bg-orange-100 dark:bg-orange-900'}`}>
              <BarChart2 className={marge >= 0 ? 'text-blue-600 dark:text-blue-400' : 'text-orange-600 dark:text-orange-400'} size={24} />
            </div>
            <div>
              <p className="text-sm text-gray-500 dark:text-gray-400">Marge Brute</p>
              <p className={`text-2xl font-bold ${marge >= 0 ? 'text-blue-600' : 'text-orange-600'}`}>
                {marge >= 0 ? '+' : ''}{marge.toFixed(2)} €
              </p>
            </div>
          </div>
        </div>
      </div>

      {/* Graphique mensuel */}
      {chartData.length > 0 && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
          <h2 className="text-sm font-semibold text-gray-600 dark:text-gray-300 mb-4">Évolution mensuelle</h2>
          <ResponsiveContainer width="100%" height={220}>
            <BarChart data={chartData} margin={{ top: 0, right: 10, left: 0, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
              <XAxis dataKey="mois" tick={{ fontSize: 12 }} />
              <YAxis tick={{ fontSize: 12 }} tickFormatter={(v) => `${v}€`} />
              <Tooltip formatter={(value) => `${Number(value).toFixed(2)} €`} />
              <Legend />
              <Bar dataKey="ventes" name="Ventes" fill="#16a34a" radius={[4, 4, 0, 0]} />
              <Bar dataKey="achats" name="Achats" fill="#dc2626" radius={[4, 4, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      )}

      {/* Filtres */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 mb-6">
        <div className="flex flex-wrap gap-4 items-center">
          <div className="flex gap-2">
            <button
              onClick={() => setActiveTab('ventes')}
              className={`px-4 py-2 rounded-lg font-medium ${
                activeTab === 'ventes'
                  ? 'bg-green-600 text-white'
                  : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'
              }`}
            >
              <FileSpreadsheet size={16} className="inline mr-2" />
              Journal des Ventes
            </button>
            <button
              onClick={() => setActiveTab('achats')}
              className={`px-4 py-2 rounded-lg font-medium ${
                activeTab === 'achats'
                  ? 'bg-red-600 text-white'
                  : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'
              }`}
            >
              <FileSpreadsheet size={16} className="inline mr-2" />
              Journal des Achats
            </button>
          </div>

          {activeTab === 'achats' && (
            <div className="flex gap-3 items-center">
              <select
                value={filterFournisseur}
                onChange={(e) => setFilterFournisseur(e.target.value)}
                className="px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
              >
                <option value="">Tous les fournisseurs</option>
                {fournisseurs.map(f => (
                  <option key={f} value={f}>{f}</option>
                ))}
              </select>
              <label className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300 cursor-pointer">
                <input
                  type="checkbox"
                  checked={groupByCommande}
                  onChange={(e) => setGroupByCommande(e.target.checked)}
                  className="rounded"
                />
                Grouper par commande
              </label>
            </div>
          )}

          <div className="flex gap-2 items-center ml-auto">
            <label className="text-sm text-gray-500">Du:</label>
            <input
              type="date"
              value={dateDebut}
              onChange={(e) => setDateDebut(e.target.value)}
              className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
            />
            <label className="text-sm text-gray-500">Au:</label>
            <input
              type="date"
              value={dateFin}
              onChange={(e) => setDateFin(e.target.value)}
              className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
            />
          </div>
        </div>
      </div>

      {/* Tableau */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
        {loading ? (
          <div className="p-8 text-center text-gray-500">Chargement...</div>
        ) : activeTab === 'ventes' ? (
          factures.length === 0 ? (
            <div className="p-8 text-center text-gray-500">Aucune vente sur cette période</div>
          ) : (
            <table className="w-full">
              <thead className="bg-gray-50 dark:bg-gray-700">
                <tr>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Date</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">N° Facture</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Client</th>
                  <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Montant</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                {factures.map((facture) => (
                  <tr key={facture.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{fmtDate(facture.date_facture)}</td>
                    <td className="px-4 py-3 font-mono text-sm text-gray-900 dark:text-white">{facture.numero}</td>
                    <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{facture.client?.raison_sociale}</td>
                    <td className="px-4 py-3 text-right text-sm font-medium text-green-600">
                      +{facture.montant_ht?.toFixed(2)} €
                    </td>
                  </tr>
                ))}
                <tr className="bg-gray-50 dark:bg-gray-700 font-bold">
                  <td colSpan={3} className="px-4 py-3 text-right text-gray-900 dark:text-white">Total:</td>
                  <td className="px-4 py-3 text-right text-green-600">{totalVentes.toFixed(2)} €</td>
                </tr>
              </tbody>
            </table>
          )
        ) : receptionsFiltrees.length === 0 ? (
          <div className="p-8 text-center text-gray-500">Aucun achat sur cette période</div>
        ) : groupByCommande && achatsGroupes ? (
          <table className="w-full">
            <thead className="bg-gray-50 dark:bg-gray-700">
              <tr>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Commande</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Fournisseur</th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Lignes</th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Total</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
              {achatsGroupes.map((g) => (
                <tr key={g.numero} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                  <td className="px-4 py-3 font-mono text-sm text-gray-900 dark:text-white">{g.numero}</td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{g.fournisseur}</td>
                  <td className="px-4 py-3 text-right text-sm text-gray-600 dark:text-gray-300">{g.lignes}</td>
                  <td className="px-4 py-3 text-right text-sm font-medium text-red-600">{g.total.toFixed(2)} €</td>
                </tr>
              ))}
              <tr className="bg-gray-50 dark:bg-gray-700 font-bold">
                <td colSpan={3} className="px-4 py-3 text-right text-gray-900 dark:text-white">Total:</td>
                <td className="px-4 py-3 text-right text-red-600">
                  {achatsGroupes.reduce((s, g) => s + g.total, 0).toFixed(2)} €
                </td>
              </tr>
            </tbody>
          </table>
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50 dark:bg-gray-700">
              <tr>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Date</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Commande</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Fournisseur</th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Quantité</th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Montant</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
              {receptionsFiltrees.map((reception) => {
                const montant = getMontantReception(reception);
                return (
                  <tr key={reception.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{fmtDate(reception.date_reception)}</td>
                    <td className="px-4 py-3 font-mono text-sm text-gray-900 dark:text-white">{reception.ligne_commande?.commande?.numero}</td>
                    <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{reception.ligne_commande?.commande?.fournisseur?.raison_sociale}</td>
                    <td className="px-4 py-3 text-right text-sm text-gray-600 dark:text-gray-300">{reception.quantite_recue}</td>
                    <td className="px-4 py-3 text-right text-sm font-medium text-red-600">
                      {montant.toFixed(2)} €
                    </td>
                  </tr>
                );
              })}
              <tr className="bg-gray-50 dark:bg-gray-700 font-bold">
                <td colSpan={4} className="px-4 py-3 text-right text-gray-900 dark:text-white">Total:</td>
                <td className="px-4 py-3 text-right text-red-600">{totalAchats.toFixed(2)} €</td>
              </tr>
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
