import { useState, useEffect, useCallback } from 'react';
import { Download, FileSpreadsheet, TrendingUp, TrendingDown } from 'lucide-react';
import { getFactures, getReceptions } from '../services/api';
import type { Facture } from '../services/api';

interface ReceptionLigne {
  id: number;
  date_reception: string;
  quantite_recue: number;
  prix_unitaire?: number;
  commande?: {
    numero: string;
    fournisseur?: {
      raison_sociale: string;
    };
  };
}

export default function ComptabilitePage() {
  const [activeTab, setActiveTab] = useState<'ventes' | 'achats'>('ventes');
  const [factures, setFactures] = useState<Facture[]>([]);
  const [receptions, setReceptions] = useState<ReceptionLigne[]>([]);
  const [loading, setLoading] = useState(true);
  const [dateDebut, setDateDebut] = useState(() => {
    const d = new Date();
    d.setMonth(d.getMonth() - 1);
    return d.toISOString().split('T')[0];
  });
  const [dateFin, setDateFin] = useState(new Date().toISOString().split('T')[0]);

  const loadData = useCallback(async () => {
    try {
      setLoading(true);
      if (activeTab === 'ventes') {
        const response = await getFactures({ statut: 'emise' });
        const filtered = (response.data || []).filter((f: Facture) => {
          const date = new Date(f.date_facture);
          return date >= new Date(dateDebut) && date <= new Date(dateFin);
        });
        setFactures(filtered);
      } else {
        const response = await getReceptions();
        const data = Array.isArray(response) ? response : (response?.data || []);
        const filtered = data.filter((r: ReceptionLigne) => {
          const date = new Date(r.date_reception);
          return date >= new Date(dateDebut) && date <= new Date(dateFin);
        });
        setReceptions(filtered);
      }
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }, [activeTab, dateDebut, dateFin]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const totalVentes = factures.reduce((sum, f) => sum + (f.montant_ht || 0), 0);
  const totalAchats = receptions.reduce((sum, r) => sum + ((r.quantite_recue || 0) * (r.prix_unitaire || 0)), 0);

  const exportCSV = () => {
    let csv = '';
    if (activeTab === 'ventes') {
      csv = 'Date;Numéro;Client;Montant\n';
      factures.forEach(f => {
        csv += `${f.date_facture};${f.numero};${f.client?.raison_sociale || ''};${f.montant_ht?.toFixed(2)}\n`;
      });
    } else {
      csv = 'Date;Commande;Fournisseur;Quantité;Montant\n';
      receptions.forEach(r => {
        const montant = (r.quantite_recue || 0) * (r.prix_unitaire || 0);
        csv += `${r.date_reception};${r.commande?.numero || ''};${r.commande?.fournisseur?.raison_sociale || ''};${r.quantite_recue};${montant.toFixed(2)}\n`;
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

      {/* Résumé */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
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
      </div>

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
                    <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{facture.date_facture}</td>
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
        ) : (
          receptions.length === 0 ? (
            <div className="p-8 text-center text-gray-500">Aucun achat sur cette période</div>
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
                {receptions.map((reception) => {
                  const montant = (reception.quantite_recue || 0) * (reception.prix_unitaire || 0);
                  return (
                    <tr key={reception.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                      <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{reception.date_reception}</td>
                      <td className="px-4 py-3 font-mono text-sm text-gray-900 dark:text-white">{reception.commande?.numero}</td>
                      <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{reception.commande?.fournisseur?.raison_sociale}</td>
                      <td className="px-4 py-3 text-right text-sm text-gray-600 dark:text-gray-300">{reception.quantite_recue}</td>
                      <td className="px-4 py-3 text-right text-sm font-medium text-red-600">
                        -{montant.toFixed(2)} €
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
          )
        )}
      </div>
    </div>
  );
}
