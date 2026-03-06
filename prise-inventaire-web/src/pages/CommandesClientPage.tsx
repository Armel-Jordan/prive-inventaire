import { useState, useEffect, useCallback } from 'react';
import { Plus, Eye, Check, X, FileText, Send } from 'lucide-react';
import {
  getCommandesClient,
  getClientsActifs,
  createCommandeClient,
  soumettreCommandeClient,
  accepterCommandeClient,
  refuserCommandeClient,
  creerFactureDepuisCommande,
} from '../services/api';
import type { ComClientEntete, Client } from '../services/api';
import { getProduits } from '../services/api';
import type { Produit } from '../types';

export default function CommandesClientPage() {
  const [commandes, setCommandes] = useState<ComClientEntete[]>([]);
  const [clients, setClients] = useState<Client[]>([]);
  const [produits, setProduits] = useState<Produit[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterStatut, setFilterStatut] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [selectedCommande, setSelectedCommande] = useState<ComClientEntete | null>(null);
  const [showRefusModal, setShowRefusModal] = useState(false);
  const [motifRefus, setMotifRefus] = useState('');

  const [formData, setFormData] = useState({
    client_id: '',
    date_commande: new Date().toISOString().split('T')[0],
    date_livraison_souhaitee: '',
    remise_globale: '0',
    notes: '',
  });
  const [lignes, setLignes] = useState<{ produit_id: number; quantite: number; prix_unitaire_ht: number }[]>([]);

  const loadData = useCallback(async () => {
    try {
      setLoading(true);
      const [commandesRes, clientsRes, produitsRes] = await Promise.all([
        getCommandesClient({ statut: filterStatut || undefined }),
        getClientsActifs(),
        getProduits(),
      ]);
      setCommandes(commandesRes.data || []);
      setClients(clientsRes || []);
      setProduits(produitsRes || []);
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }, [filterStatut]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (lignes.length === 0) {
      alert('Ajoutez au moins une ligne');
      return;
    }
    try {
      await createCommandeClient({
        client_id: parseInt(formData.client_id),
        date_commande: formData.date_commande,
        date_livraison_souhaitee: formData.date_livraison_souhaitee || undefined,
        remise_globale: parseFloat(formData.remise_globale) || 0,
        notes: formData.notes || undefined,
        lignes: lignes.map(l => ({
          produit_id: l.produit_id,
          quantite: l.quantite,
          prix_unitaire_ht: l.prix_unitaire_ht,
        })),
      });
      setShowModal(false);
      resetForm();
      loadData();
    } catch (error) {
      console.error('Erreur création:', error);
    }
  };

  const handleSoumettre = async (id: number) => {
    try {
      await soumettreCommandeClient(id);
      loadData();
    } catch (error) {
      console.error('Erreur soumission:', error);
    }
  };

  const handleAccepter = async (id: number) => {
    try {
      await accepterCommandeClient(id);
      loadData();
    } catch (error) {
      console.error('Erreur acceptation:', error);
    }
  };

  const handleRefuser = async () => {
    if (!selectedCommande || !motifRefus.trim()) return;
    try {
      await refuserCommandeClient(selectedCommande.id, motifRefus);
      setShowRefusModal(false);
      setMotifRefus('');
      setSelectedCommande(null);
      loadData();
    } catch (error) {
      console.error('Erreur refus:', error);
    }
  };

  const handleCreerFacture = async (id: number) => {
    try {
      await creerFactureDepuisCommande(id);
      loadData();
    } catch (error) {
      console.error('Erreur création facture:', error);
    }
  };

  const addLigne = () => {
    setLignes([...lignes, { produit_id: 0, quantite: 1, prix_unitaire_ht: 0 }]);
  };

  const updateLigne = (index: number, field: string, value: number) => {
    const newLignes = [...lignes];
    (newLignes[index] as Record<string, number>)[field] = value;
    // Prix par défaut si disponible (à adapter selon les données produit)
    setLignes(newLignes);
  };

  const removeLigne = (index: number) => {
    setLignes(lignes.filter((_, i) => i !== index));
  };

  const resetForm = () => {
    setFormData({
      client_id: '',
      date_commande: new Date().toISOString().split('T')[0],
      date_livraison_souhaitee: '',
      remise_globale: '0',
      notes: '',
    });
    setLignes([]);
  };

  const getStatutBadge = (statut: string) => {
    const styles: Record<string, string> = {
      brouillon: 'bg-gray-100 text-gray-800',
      en_attente: 'bg-yellow-100 text-yellow-800',
      acceptee: 'bg-green-100 text-green-800',
      refusee: 'bg-red-100 text-red-800',
      facturee: 'bg-blue-100 text-blue-800',
      annulee: 'bg-gray-100 text-gray-500',
    };
    const labels: Record<string, string> = {
      brouillon: 'Brouillon',
      en_attente: 'En attente',
      acceptee: 'Acceptée',
      refusee: 'Refusée',
      facturee: 'Facturée',
      annulee: 'Annulée',
    };
    return (
      <span className={`px-2 py-1 text-xs rounded-full ${styles[statut] || 'bg-gray-100'}`}>
        {labels[statut] || statut}
      </span>
    );
  };

  const totalLignes = lignes.reduce((sum, l) => sum + l.quantite * l.prix_unitaire_ht, 0);

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Commandes Client</h1>
        <button
          onClick={() => { resetForm(); setShowModal(true); }}
          className="flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700"
        >
          <Plus size={20} />
          Nouvelle commande
        </button>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 mb-6">
        <div className="flex gap-4">
          <select
            value={filterStatut}
            onChange={(e) => setFilterStatut(e.target.value)}
            className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          >
            <option value="">Tous les statuts</option>
            <option value="brouillon">Brouillon</option>
            <option value="en_attente">En attente</option>
            <option value="acceptee">Acceptée</option>
            <option value="refusee">Refusée</option>
            <option value="facturee">Facturée</option>
          </select>
        </div>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
        {loading ? (
          <div className="p-8 text-center text-gray-500">Chargement...</div>
        ) : commandes.length === 0 ? (
          <div className="p-8 text-center text-gray-500">Aucune commande trouvée</div>
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50 dark:bg-gray-700">
              <tr>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">N°</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Client</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Date</th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Montant TTC</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Statut</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
              {commandes.map((cmd) => (
                <tr key={cmd.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                  <td className="px-4 py-3 font-mono text-sm text-gray-900 dark:text-white">{cmd.numero}</td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{cmd.client?.raison_sociale}</td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{cmd.date_commande}</td>
                  <td className="px-4 py-3 text-right text-sm font-medium text-gray-900 dark:text-white">
                    {cmd.montant_ttc?.toFixed(2)} €
                  </td>
                  <td className="px-4 py-3 text-center">{getStatutBadge(cmd.statut)}</td>
                  <td className="px-4 py-3">
                    <div className="flex justify-center gap-1">
                      <button
                        onClick={() => { setSelectedCommande(cmd); setShowDetailModal(true); }}
                        className="p-1 text-gray-600 hover:bg-gray-100 rounded"
                        title="Voir détails"
                      >
                        <Eye size={18} />
                      </button>
                      {cmd.statut === 'brouillon' && (
                        <button
                          onClick={() => handleSoumettre(cmd.id)}
                          className="p-1 text-blue-600 hover:bg-blue-50 rounded"
                          title="Soumettre"
                        >
                          <Send size={18} />
                        </button>
                      )}
                      {cmd.statut === 'en_attente' && (
                        <>
                          <button
                            onClick={() => handleAccepter(cmd.id)}
                            className="p-1 text-green-600 hover:bg-green-50 rounded"
                            title="Accepter"
                          >
                            <Check size={18} />
                          </button>
                          <button
                            onClick={() => { setSelectedCommande(cmd); setShowRefusModal(true); }}
                            className="p-1 text-red-600 hover:bg-red-50 rounded"
                            title="Refuser"
                          >
                            <X size={18} />
                          </button>
                        </>
                      )}
                      {cmd.statut === 'acceptee' && (
                        <button
                          onClick={() => handleCreerFacture(cmd.id)}
                          className="p-1 text-purple-600 hover:bg-purple-50 rounded"
                          title="Créer facture"
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
            <h2 className="text-xl font-bold mb-4 dark:text-white">Nouvelle commande</h2>
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
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Date commande *</label>
                  <input
                    type="date"
                    required
                    value={formData.date_commande}
                    onChange={(e) => setFormData({ ...formData, date_commande: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Date livraison souhaitée</label>
                  <input
                    type="date"
                    value={formData.date_livraison_souhaitee}
                    onChange={(e) => setFormData({ ...formData, date_livraison_souhaitee: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Remise globale (%)</label>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    max="100"
                    value={formData.remise_globale}
                    onChange={(e) => setFormData({ ...formData, remise_globale: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">Notes</label>
                <textarea
                  value={formData.notes}
                  onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  rows={2}
                />
              </div>

              <div>
                <div className="flex justify-between items-center mb-2">
                  <label className="text-sm font-medium dark:text-gray-300">Lignes de commande</label>
                  <button type="button" onClick={addLigne} className="text-sm text-purple-600 hover:underline">
                    + Ajouter ligne
                  </button>
                </div>
                {lignes.length === 0 ? (
                  <p className="text-sm text-gray-500 italic">Aucune ligne. Cliquez sur "Ajouter ligne".</p>
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
                          value={ligne.prix_unitaire_ht}
                          onChange={(e) => updateLigne(idx, 'prix_unitaire_ht', parseFloat(e.target.value) || 0)}
                          className="w-24 px-2 py-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                          placeholder="Prix HT"
                        />
                        <span className="text-sm text-gray-600 dark:text-gray-400 w-20 text-right">
                          {(ligne.quantite * ligne.prix_unitaire_ht).toFixed(2)} €
                        </span>
                        <button type="button" onClick={() => removeLigne(idx)} className="text-red-500 hover:text-red-700">
                          <X size={16} />
                        </button>
                      </div>
                    ))}
                    <div className="text-right font-medium text-gray-900 dark:text-white">
                      Total HT: {totalLignes.toFixed(2)} €
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
      {showDetailModal && selectedCommande && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-2xl">
            <h2 className="text-xl font-bold mb-4 dark:text-white">
              Commande {selectedCommande.numero}
            </h2>
            <div className="space-y-3">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <span className="text-sm text-gray-500">Client:</span>
                  <p className="font-medium dark:text-white">{selectedCommande.client?.raison_sociale}</p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Statut:</span>
                  <p>{getStatutBadge(selectedCommande.statut)}</p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Date commande:</span>
                  <p className="dark:text-white">{selectedCommande.date_commande}</p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Date livraison souhaitée:</span>
                  <p className="dark:text-white">{selectedCommande.date_livraison_souhaitee || '-'}</p>
                </div>
              </div>
              <div className="border-t pt-3 dark:border-gray-700">
                <div className="flex justify-between">
                  <span className="text-gray-500">Montant HT:</span>
                  <span className="dark:text-white">{selectedCommande.montant_ht?.toFixed(2)} €</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">TVA:</span>
                  <span className="dark:text-white">{selectedCommande.montant_tva?.toFixed(2)} €</span>
                </div>
                <div className="flex justify-between font-bold">
                  <span className="dark:text-white">Total TTC:</span>
                  <span className="dark:text-white">{selectedCommande.montant_ttc?.toFixed(2)} €</span>
                </div>
              </div>
              {selectedCommande.motif_refus && (
                <div className="bg-red-50 dark:bg-red-900/30 p-3 rounded">
                  <span className="text-sm text-red-600 dark:text-red-400">Motif de refus: {selectedCommande.motif_refus}</span>
                </div>
              )}
            </div>
            <div className="flex justify-end mt-6">
              <button
                onClick={() => { setShowDetailModal(false); setSelectedCommande(null); }}
                className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-white"
              >
                Fermer
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal refus */}
      {showRefusModal && selectedCommande && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h2 className="text-xl font-bold mb-4 dark:text-white">Refuser la commande</h2>
            <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
              Commande {selectedCommande.numero} - {selectedCommande.client?.raison_sociale}
            </p>
            <div>
              <label className="block text-sm font-medium mb-1 dark:text-gray-300">Motif du refus *</label>
              <textarea
                required
                value={motifRefus}
                onChange={(e) => setMotifRefus(e.target.value)}
                className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                rows={3}
                placeholder="Indiquez la raison du refus..."
              />
            </div>
            <div className="flex justify-end gap-3 mt-6">
              <button
                onClick={() => { setShowRefusModal(false); setMotifRefus(''); setSelectedCommande(null); }}
                className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-white"
              >
                Annuler
              </button>
              <button
                onClick={handleRefuser}
                disabled={!motifRefus.trim()}
                className="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"
              >
                Refuser
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
