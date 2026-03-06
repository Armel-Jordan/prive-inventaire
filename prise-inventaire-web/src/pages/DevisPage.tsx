import { useState, useEffect, useCallback } from 'react';
import { Plus, Eye, FileText, X, Send } from 'lucide-react';
import { getClientsActifs, getProduits } from '../services/api';
import type { Client } from '../services/api';
import type { Produit } from '../types';

interface Devis {
  id: number;
  numero: string;
  client_id: number;
  client?: Client;
  date_devis: string;
  date_validite: string;
  statut: 'brouillon' | 'envoye' | 'accepte' | 'refuse' | 'expire';
  montant_total: number;
  lignes: DevisLigne[];
}

interface DevisLigne {
  produit_id: number;
  produit?: Produit;
  quantite: number;
  prix_unitaire: number;
}

export default function DevisPage() {
  const [devisList, setDevisList] = useState<Devis[]>([]);
  const [clients, setClients] = useState<Client[]>([]);
  const [produits, setProduits] = useState<Produit[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterStatut, setFilterStatut] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [selectedDevis, setSelectedDevis] = useState<Devis | null>(null);

  const [formData, setFormData] = useState({
    client_id: '',
    date_devis: new Date().toISOString().split('T')[0],
    date_validite: '',
    notes: '',
  });
  const [lignes, setLignes] = useState<{ produit_id: number; quantite: number; prix_unitaire: number }[]>([]);

  const loadData = useCallback(async () => {
    try {
      setLoading(true);
      const [clientsRes, produitsRes] = await Promise.all([
        getClientsActifs(),
        getProduits(),
      ]);
      setClients(clientsRes || []);
      setProduits(produitsRes || []);
      
      // Données de démo
      setDevisList([
        {
          id: 1,
          numero: 'DEV-2026-0001',
          client_id: 1,
          client: clientsRes?.[0],
          date_devis: '2026-03-01',
          date_validite: '2026-03-31',
          statut: 'envoye',
          montant_total: 1500.00,
          lignes: [],
        },
        {
          id: 2,
          numero: 'DEV-2026-0002',
          client_id: 2,
          client: clientsRes?.[1],
          date_devis: '2026-03-05',
          date_validite: '2026-04-05',
          statut: 'brouillon',
          montant_total: 2300.00,
          lignes: [],
        },
      ]);
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (lignes.length === 0) {
      alert('Ajoutez au moins une ligne');
      return;
    }
    
    const montantTotal = lignes.reduce((sum, l) => sum + l.quantite * l.prix_unitaire, 0);
    const newDevis: Devis = {
      id: devisList.length + 1,
      numero: `DEV-2026-${String(devisList.length + 1).padStart(4, '0')}`,
      client_id: parseInt(formData.client_id),
      client: clients.find(c => c.id === parseInt(formData.client_id)),
      date_devis: formData.date_devis,
      date_validite: formData.date_validite,
      statut: 'brouillon',
      montant_total: montantTotal,
      lignes: lignes.map(l => ({
        ...l,
        produit: produits.find(p => p.id === l.produit_id),
      })),
    };
    
    setDevisList([...devisList, newDevis]);
    setShowModal(false);
    resetForm();
  };

  const handleEnvoyer = (id: number) => {
    setDevisList(devisList.map(d => 
      d.id === id ? { ...d, statut: 'envoye' as const } : d
    ));
  };

  const handleConvertir = (devis: Devis) => {
    alert(`Devis ${devis.numero} converti en commande ! (Fonctionnalité à implémenter)`);
    setDevisList(devisList.map(d => 
      d.id === devis.id ? { ...d, statut: 'accepte' as const } : d
    ));
  };

  const addLigne = () => {
    setLignes([...lignes, { produit_id: 0, quantite: 1, prix_unitaire: 0 }]);
  };

  const updateLigne = (index: number, field: string, value: number) => {
    const newLignes = [...lignes];
    (newLignes[index] as Record<string, number>)[field] = value;
    setLignes(newLignes);
  };

  const removeLigne = (index: number) => {
    setLignes(lignes.filter((_, i) => i !== index));
  };

  const resetForm = () => {
    setFormData({
      client_id: '',
      date_devis: new Date().toISOString().split('T')[0],
      date_validite: '',
      notes: '',
    });
    setLignes([]);
  };

  const getStatutBadge = (statut: string) => {
    const styles: Record<string, string> = {
      brouillon: 'bg-gray-100 text-gray-800',
      envoye: 'bg-blue-100 text-blue-800',
      accepte: 'bg-green-100 text-green-800',
      refuse: 'bg-red-100 text-red-800',
      expire: 'bg-yellow-100 text-yellow-800',
    };
    const labels: Record<string, string> = {
      brouillon: 'Brouillon',
      envoye: 'Envoyé',
      accepte: 'Accepté',
      refuse: 'Refusé',
      expire: 'Expiré',
    };
    return (
      <span className={`px-2 py-1 text-xs rounded-full ${styles[statut] || 'bg-gray-100'}`}>
        {labels[statut] || statut}
      </span>
    );
  };

  const filteredDevis = filterStatut 
    ? devisList.filter(d => d.statut === filterStatut)
    : devisList;

  const totalLignes = lignes.reduce((sum, l) => sum + l.quantite * l.prix_unitaire, 0);

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Devis</h1>
        <button
          onClick={() => { resetForm(); setShowModal(true); }}
          className="flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700"
        >
          <Plus size={20} />
          Nouveau devis
        </button>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 mb-6">
        <select
          value={filterStatut}
          onChange={(e) => setFilterStatut(e.target.value)}
          className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
        >
          <option value="">Tous les statuts</option>
          <option value="brouillon">Brouillon</option>
          <option value="envoye">Envoyé</option>
          <option value="accepte">Accepté</option>
          <option value="refuse">Refusé</option>
        </select>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
        {loading ? (
          <div className="p-8 text-center text-gray-500">Chargement...</div>
        ) : filteredDevis.length === 0 ? (
          <div className="p-8 text-center text-gray-500">Aucun devis trouvé</div>
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50 dark:bg-gray-700">
              <tr>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">N°</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Client</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Date</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Validité</th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Montant</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Statut</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
              {filteredDevis.map((devis) => (
                <tr key={devis.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                  <td className="px-4 py-3 font-mono text-sm text-gray-900 dark:text-white">{devis.numero}</td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{devis.client?.raison_sociale}</td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{devis.date_devis}</td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{devis.date_validite}</td>
                  <td className="px-4 py-3 text-right text-sm font-medium text-gray-900 dark:text-white">
                    {devis.montant_total.toFixed(2)} €
                  </td>
                  <td className="px-4 py-3 text-center">{getStatutBadge(devis.statut)}</td>
                  <td className="px-4 py-3">
                    <div className="flex justify-center gap-1">
                      <button
                        onClick={() => { setSelectedDevis(devis); setShowDetailModal(true); }}
                        className="p-1 text-gray-600 hover:bg-gray-100 rounded"
                        title="Voir"
                      >
                        <Eye size={18} />
                      </button>
                      {devis.statut === 'brouillon' && (
                        <button
                          onClick={() => handleEnvoyer(devis.id)}
                          className="p-1 text-blue-600 hover:bg-blue-50 rounded"
                          title="Envoyer"
                        >
                          <Send size={18} />
                        </button>
                      )}
                      {devis.statut === 'envoye' && (
                        <button
                          onClick={() => handleConvertir(devis)}
                          className="p-1 text-green-600 hover:bg-green-50 rounded"
                          title="Convertir en commande"
                        >
                          <FileText size={18} />
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {/* Modal création */}
      {showModal && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-3xl max-h-[90vh] overflow-y-auto">
            <h2 className="text-xl font-bold mb-4 dark:text-white">Nouveau devis</h2>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Client *</label>
                  <select
                    required
                    value={formData.client_id}
                    onChange={(e) => setFormData({ ...formData, client_id: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  >
                    <option value="">Sélectionner...</option>
                    {clients.map(c => (
                      <option key={c.id} value={c.id}>{c.raison_sociale}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Date devis *</label>
                  <input
                    type="date"
                    required
                    value={formData.date_devis}
                    onChange={(e) => setFormData({ ...formData, date_devis: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Date validité *</label>
                  <input
                    type="date"
                    required
                    value={formData.date_validite}
                    onChange={(e) => setFormData({ ...formData, date_validite: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
              </div>

              <div>
                <div className="flex justify-between items-center mb-2">
                  <label className="text-sm font-medium dark:text-gray-300">Lignes</label>
                  <button type="button" onClick={addLigne} className="text-sm text-purple-600 hover:underline">
                    + Ajouter ligne
                  </button>
                </div>
                {lignes.length === 0 ? (
                  <p className="text-sm text-gray-500 italic">Aucune ligne.</p>
                ) : (
                  <div className="space-y-2">
                    {lignes.map((ligne, idx) => (
                      <div key={idx} className="flex gap-2 items-center">
                        <select
                          value={ligne.produit_id}
                          onChange={(e) => updateLigne(idx, 'produit_id', parseInt(e.target.value))}
                          className="flex-1 px-2 py-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                        >
                          <option value={0}>Produit...</option>
                          {produits.map(p => (
                            <option key={p.id} value={p.id}>{p.description}</option>
                          ))}
                        </select>
                        <input
                          type="number"
                          min="1"
                          value={ligne.quantite}
                          onChange={(e) => updateLigne(idx, 'quantite', parseInt(e.target.value) || 1)}
                          className="w-20 px-2 py-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                          placeholder="Qté"
                        />
                        <input
                          type="number"
                          step="0.01"
                          value={ligne.prix_unitaire}
                          onChange={(e) => updateLigne(idx, 'prix_unitaire', parseFloat(e.target.value) || 0)}
                          className="w-24 px-2 py-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                          placeholder="Prix"
                        />
                        <span className="text-sm text-gray-600 dark:text-gray-400 w-20 text-right">
                          {(ligne.quantite * ligne.prix_unitaire).toFixed(2)} €
                        </span>
                        <button type="button" onClick={() => removeLigne(idx)} className="text-red-500">
                          <X size={16} />
                        </button>
                      </div>
                    ))}
                    <div className="text-right font-medium text-gray-900 dark:text-white">
                      Total: {totalLignes.toFixed(2)} €
                    </div>
                  </div>
                )}
              </div>

              <div className="flex justify-end gap-3 mt-6">
                <button
                  type="button"
                  onClick={() => setShowModal(false)}
                  className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-white"
                >
                  Annuler
                </button>
                <button type="submit" className="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                  Créer
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal détail */}
      {showDetailModal && selectedDevis && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg">
            <h2 className="text-xl font-bold mb-4 dark:text-white">Devis {selectedDevis.numero}</h2>
            <div className="space-y-3">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <span className="text-sm text-gray-500">Client:</span>
                  <p className="font-medium dark:text-white">{selectedDevis.client?.raison_sociale}</p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Statut:</span>
                  <p>{getStatutBadge(selectedDevis.statut)}</p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Date:</span>
                  <p className="dark:text-white">{selectedDevis.date_devis}</p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Validité:</span>
                  <p className="dark:text-white">{selectedDevis.date_validite}</p>
                </div>
              </div>
              <div className="border-t pt-3 dark:border-gray-700">
                <div className="flex justify-between font-bold text-lg">
                  <span className="dark:text-white">Total:</span>
                  <span className="dark:text-white">{selectedDevis.montant_total.toFixed(2)} €</span>
                </div>
              </div>
            </div>
            <div className="flex justify-end mt-6">
              <button
                onClick={() => { setShowDetailModal(false); setSelectedDevis(null); }}
                className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-white"
              >
                Fermer
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
