import { useState, useEffect, useCallback } from 'react';
import { Eye, CreditCard, FileText, Send, Trash2, Plus, X } from 'lucide-react';
import {
  getFactures,
  getFacture,
  emettreFacture,
  enregistrerPaiement,
  creerBonLivraison,
  deleteFacture,
  createFacture,
  getClientsActifs,
  getProduits,
} from '../services/api';
import type { Facture, Client } from '../services/api';
import type { Produit } from '../types';

export default function FacturesPage() {
  const [factures, setFactures] = useState<Facture[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterStatut, setFilterStatut] = useState('');
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [showPaiementModal, setShowPaiementModal] = useState(false);
  const [selectedFacture, setSelectedFacture] = useState<Facture | null>(null);
  const [paiementData, setPaiementData] = useState({
    montant: '',
    date_paiement: new Date().toISOString().split('T')[0],
    mode_paiement: 'virement',
    reference: '',
  });
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [clients, setClients] = useState<Client[]>([]);
  const [produits, setProduits] = useState<Produit[]>([]);
  const [createForm, setCreateForm] = useState({
    client_id: '',
    date_facture: new Date().toISOString().split('T')[0],
    date_echeance: '',
    notes: '',
  });
  const [createLignes, setCreateLignes] = useState<{ produit_id: number; quantite: number; prix_unitaire_ht: number }[]>([]);

  const loadMeta = async () => {
    const [c, p] = await Promise.all([getClientsActifs(), getProduits()]);
    setClients(c || []);
    setProduits(p || []);
  };

  const loadFactures = useCallback(async () => {
    try {
      setLoading(true);
      const response = await getFactures({ statut: filterStatut || undefined });
      setFactures(response.data || []);
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }, [filterStatut]);

  useEffect(() => {
    loadFactures();
    loadMeta();
  }, [loadFactures]);

  const handleVoirDetail = async (id: number) => {
    try {
      const facture = await getFacture(id);
      setSelectedFacture(facture);
      setShowDetailModal(true);
    } catch (error) {
      console.error('Erreur chargement détail:', error);
    }
  };

  const handleEmettre = async (id: number) => {
    try {
      await emettreFacture(id);
      loadFactures();
    } catch (error) {
      console.error('Erreur émission:', error);
    }
  };

  const handleCreerBL = async (id: number) => {
    try {
      await creerBonLivraison(id);
      loadFactures();
    } catch (error) {
      console.error('Erreur création BL:', error);
    }
  };

  const handleDelete = async (id: number) => {
    if (!confirm('Supprimer cette facture ?')) return;
    try {
      await deleteFacture(id);
      loadFactures();
    } catch {
      alert('Impossible de supprimer cette facture.');
    }
  };

  const handleCreateFacture = async (e: React.FormEvent) => {
    e.preventDefault();
    if (createLignes.length === 0) { alert('Ajoutez au moins une ligne'); return; }
    try {
      await createFacture({
        client_id: parseInt(createForm.client_id),
        date_facture: createForm.date_facture,
        date_echeance: createForm.date_echeance || undefined,
        notes: createForm.notes || undefined,
        lignes: createLignes,
      });
      setShowCreateModal(false);
      setCreateForm({ client_id: '', date_facture: new Date().toISOString().split('T')[0], date_echeance: '', notes: '' });
      setCreateLignes([]);
      loadFactures();
    } catch (error) {
      console.error('Erreur création facture:', error);
    }
  };

  const handlePaiement = async () => {
    if (!selectedFacture || !paiementData.montant) return;
    try {
      await enregistrerPaiement(selectedFacture.id, {
        montant: parseFloat(paiementData.montant),
        date_paiement: paiementData.date_paiement,
        mode_paiement: paiementData.mode_paiement,
        reference: paiementData.reference || undefined,
      });
      setShowPaiementModal(false);
      setPaiementData({
        montant: '',
        date_paiement: new Date().toISOString().split('T')[0],
        mode_paiement: 'virement',
        reference: '',
      });
      setSelectedFacture(null);
      loadFactures();
    } catch (error) {
      console.error('Erreur paiement:', error);
    }
  };

  const getStatutBadge = (statut: string) => {
    const styles: Record<string, string> = {
      brouillon: 'bg-gray-100 text-gray-800',
      emise: 'bg-blue-100 text-blue-800',
      partiellement_payee: 'bg-yellow-100 text-yellow-800',
      payee: 'bg-green-100 text-green-800',
      annulee: 'bg-red-100 text-red-800',
    };
    const labels: Record<string, string> = {
      brouillon: 'Brouillon',
      emise: 'Émise',
      partiellement_payee: 'Partiel',
      payee: 'Payée',
      annulee: 'Annulée',
    };
    return (
      <span className={`px-2 py-1 text-xs rounded-full ${styles[statut] || 'bg-gray-100'}`}>
        {labels[statut] || statut}
      </span>
    );
  };

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Factures</h1>
        <button
          onClick={() => setShowCreateModal(true)}
          className="flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700"
        >
          <Plus size={20} />
          Nouvelle facture
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
            <option value="emise">Émise</option>
            <option value="partiellement_payee">Partiellement payée</option>
            <option value="payee">Payée</option>
          </select>
        </div>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
        {loading ? (
          <div className="p-8 text-center text-gray-500">Chargement...</div>
        ) : factures.length === 0 ? (
          <div className="p-8 text-center text-gray-500">Aucune facture trouvée</div>
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50 dark:bg-gray-700">
              <tr>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">N°</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Client</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Date</th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Montant</th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Reste à payer</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Statut</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
              {factures.map((facture) => (
                <tr key={facture.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                  <td className="px-4 py-3 font-mono text-sm text-gray-900 dark:text-white">{facture.numero}</td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{facture.client?.raison_sociale}</td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{facture.date_facture}</td>
                  <td className="px-4 py-3 text-right text-sm font-medium text-gray-900 dark:text-white">
                    {Number(facture.montant_ht ?? 0).toFixed(2)} €
                  </td>
                  <td className="px-4 py-3 text-right text-sm">
                    <span className={facture.reste_a_payer > 0 ? 'text-red-600 font-medium' : 'text-green-600'}>
                      {Number(facture.reste_a_payer ?? 0).toFixed(2)} €
                    </span>
                  </td>
                  <td className="px-4 py-3 text-center">{getStatutBadge(facture.statut)}</td>
                  <td className="px-4 py-3">
                    <div className="flex justify-center gap-1">
                      <button
                        onClick={() => handleVoirDetail(facture.id)}
                        className="p-1 text-gray-600 hover:bg-gray-100 rounded"
                        title="Voir détails"
                      >
                        <Eye size={18} />
                      </button>
                      {facture.statut === 'brouillon' && (
                        <>
                          <button
                            onClick={() => handleEmettre(facture.id)}
                            className="p-1 text-blue-600 hover:bg-blue-50 rounded"
                            title="Émettre"
                          >
                            <Send size={18} />
                          </button>
                          <button
                            onClick={() => handleDelete(facture.id)}
                            className="p-1 text-red-600 hover:bg-red-50 rounded"
                            title="Supprimer"
                          >
                            <Trash2 size={18} />
                          </button>
                        </>
                      )}
                      {(facture.statut === 'emise' || facture.statut === 'partiellement_payee') && (
                        <>
                          <button
                            onClick={() => { setSelectedFacture(facture); setPaiementData({ ...paiementData, montant: facture.reste_a_payer.toString() }); setShowPaiementModal(true); }}
                            className="p-1 text-green-600 hover:bg-green-50 rounded"
                            title="Enregistrer paiement"
                          >
                            <CreditCard size={18} />
                          </button>
                          {!facture.bon_livraison && (
                            <button
                              onClick={() => handleCreerBL(facture.id)}
                              className="p-1 text-purple-600 hover:bg-purple-50 rounded"
                              title="Créer bon de livraison"
                            >
                              <FileText size={18} />
                            </button>
                          )}
                        </>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {/* Modal détail */}
      {showDetailModal && selectedFacture && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <h2 className="text-xl font-bold mb-4 dark:text-white">
              Facture {selectedFacture.numero}
            </h2>
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <span className="text-sm text-gray-500">Client:</span>
                  <p className="font-medium dark:text-white">{selectedFacture.client?.raison_sociale}</p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Statut:</span>
                  <p>{getStatutBadge(selectedFacture.statut)}</p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Date facture:</span>
                  <p className="dark:text-white">{selectedFacture.date_facture}</p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Date échéance:</span>
                  <p className="dark:text-white">{selectedFacture.date_echeance || '-'}</p>
                </div>
              </div>

              {selectedFacture.lignes && selectedFacture.lignes.length > 0 && (
                <div className="border-t pt-4 dark:border-gray-700">
                  <h3 className="font-medium mb-2 dark:text-white">Lignes</h3>
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="text-left text-gray-500">
                        <th className="py-1">Produit</th>
                        <th className="py-1 text-right">Qté</th>
                        <th className="py-1 text-right">PU HT</th>
                        <th className="py-1 text-right">Total HT</th>
                      </tr>
                    </thead>
                    <tbody>
                      {selectedFacture.lignes.map((ligne, idx) => (
                        <tr key={idx} className="dark:text-gray-300">
                          <td className="py-1">Produit #{ligne.produit_id}</td>
                          <td className="py-1 text-right">{ligne.quantite}</td>
                          <td className="py-1 text-right">{Number(ligne.prix_unitaire_ht ?? 0).toFixed(2)} €</td>
                          <td className="py-1 text-right">{Number(ligne.montant_ht ?? 0).toFixed(2)} €</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              <div className="border-t pt-4 dark:border-gray-700">
                <div className="flex justify-between font-bold text-lg">
                  <span className="dark:text-white">Total:</span>
                  <span className="dark:text-white">{Number(selectedFacture.montant_ht ?? 0).toFixed(2)} €</span>
                </div>
                <div className="flex justify-between mt-2">
                  <span className="text-gray-500">Payé:</span>
                  <span className="text-green-600">{Number(selectedFacture.montant_paye ?? 0).toFixed(2)} €</span>
                </div>
                <div className="flex justify-between font-medium">
                  <span className="text-gray-500">Reste à payer:</span>
                  <span className={selectedFacture.reste_a_payer > 0 ? 'text-red-600' : 'text-green-600'}>
                    {Number(selectedFacture.reste_a_payer ?? 0).toFixed(2)} €
                  </span>
                </div>
              </div>

              {selectedFacture.echeances && selectedFacture.echeances.length > 0 && (
                <div className="border-t pt-4 dark:border-gray-700">
                  <h3 className="font-medium mb-2 dark:text-white">Échéances</h3>
                  <div className="space-y-2">
                    {selectedFacture.echeances.map((ech, idx) => (
                      <div key={idx} className="flex justify-between text-sm">
                        <span className="dark:text-gray-300">{ech.date_echeance}</span>
                        <span className="dark:text-white">{Number(ech.montant ?? 0).toFixed(2)} €</span>
                        <span className={ech.statut === 'payee' ? 'text-green-600' : 'text-yellow-600'}>
                          {ech.statut === 'payee' ? 'Payée' : ech.statut === 'partiellement_payee' ? 'Partiel' : 'En attente'}
                        </span>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
            <div className="flex justify-end mt-6">
              <button
                onClick={() => { setShowDetailModal(false); setSelectedFacture(null); }}
                className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-white"
              >
                Fermer
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal création manuelle */}
      {showCreateModal && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-3xl max-h-[90vh] overflow-y-auto">
            <div className="flex justify-between items-center mb-4">
              <h2 className="text-xl font-bold dark:text-white">Nouvelle facture</h2>
              <button onClick={() => setShowCreateModal(false)}><X size={20} className="text-gray-500" /></button>
            </div>
            <form onSubmit={handleCreateFacture} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Client *</label>
                  <select
                    required
                    value={createForm.client_id}
                    onChange={(e) => setCreateForm({ ...createForm, client_id: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  >
                    <option value="">Sélectionner...</option>
                    {clients.map(c => <option key={c.id} value={c.id}>{c.raison_sociale}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Date facture *</label>
                  <input type="date" required value={createForm.date_facture}
                    onChange={(e) => setCreateForm({ ...createForm, date_facture: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Date échéance</label>
                  <input type="date" value={createForm.date_echeance}
                    onChange={(e) => setCreateForm({ ...createForm, date_echeance: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Notes</label>
                  <input type="text" value={createForm.notes}
                    onChange={(e) => setCreateForm({ ...createForm, notes: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                </div>
              </div>
              <div>
                <div className="flex justify-between items-center mb-2">
                  <label className="text-sm font-medium dark:text-gray-300">Lignes</label>
                  <button type="button"
                    onClick={() => setCreateLignes([...createLignes, { produit_id: 0, quantite: 1, prix_unitaire_ht: 0 }])}
                    className="text-sm text-purple-600 hover:underline">+ Ajouter ligne</button>
                </div>
                {createLignes.length === 0 ? (
                  <p className="text-sm text-gray-500 italic">Aucune ligne.</p>
                ) : (
                  <div className="space-y-2">
                    {createLignes.map((l, idx) => (
                      <div key={idx} className="flex gap-2 items-center">
                        <select value={l.produit_id}
                          onChange={(e) => { const n = [...createLignes]; n[idx].produit_id = parseInt(e.target.value); setCreateLignes(n); }}
                          className="flex-1 px-2 py-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                          <option value={0}>Produit...</option>
                          {produits.map(p => <option key={p.id} value={p.id}>{p.description}</option>)}
                        </select>
                        <input type="number" min="1" value={l.quantite}
                          onChange={(e) => { const n = [...createLignes]; n[idx].quantite = parseInt(e.target.value) || 1; setCreateLignes(n); }}
                          className="w-20 px-2 py-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white" placeholder="Qté" />
                        <input type="number" step="0.01" value={l.prix_unitaire_ht}
                          onChange={(e) => { const n = [...createLignes]; n[idx].prix_unitaire_ht = parseFloat(e.target.value) || 0; setCreateLignes(n); }}
                          className="w-24 px-2 py-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white" placeholder="Prix HT" />
                        <span className="text-sm text-gray-600 dark:text-gray-400 w-20 text-right">
                          {(l.quantite * l.prix_unitaire_ht).toFixed(2)} €
                        </span>
                        <button type="button" onClick={() => setCreateLignes(createLignes.filter((_, i) => i !== idx))}
                          className="text-red-500 hover:text-red-700"><X size={16} /></button>
                      </div>
                    ))}
                    <div className="text-right font-medium dark:text-white">
                      Total HT: {createLignes.reduce((s, l) => s + l.quantite * l.prix_unitaire_ht, 0).toFixed(2)} €
                    </div>
                  </div>
                )}
              </div>
              <div className="flex justify-end gap-3 mt-6">
                <button type="button" onClick={() => setShowCreateModal(false)}
                  className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-white">
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

      {/* Modal paiement */}
      {showPaiementModal && selectedFacture && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h2 className="text-xl font-bold mb-4 dark:text-white">Enregistrer un paiement</h2>
            <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
              Facture {selectedFacture.numero} - Reste à payer: {Number(selectedFacture.reste_a_payer ?? 0).toFixed(2)} €
            </p>
            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">Montant *</label>
                <input
                  type="number"
                  step="0.01"
                  required
                  value={paiementData.montant}
                  onChange={(e) => setPaiementData({ ...paiementData, montant: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">Date paiement *</label>
                <input
                  type="date"
                  required
                  value={paiementData.date_paiement}
                  onChange={(e) => setPaiementData({ ...paiementData, date_paiement: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">Mode de paiement *</label>
                <select
                  value={paiementData.mode_paiement}
                  onChange={(e) => setPaiementData({ ...paiementData, mode_paiement: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                >
                  <option value="virement">Virement</option>
                  <option value="cheque">Chèque</option>
                  <option value="especes">Espèces</option>
                  <option value="carte">Carte bancaire</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">Référence</label>
                <input
                  type="text"
                  value={paiementData.reference}
                  onChange={(e) => setPaiementData({ ...paiementData, reference: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  placeholder="N° chèque, référence virement..."
                />
              </div>
            </div>
            <div className="flex justify-end gap-3 mt-6">
              <button
                onClick={() => { setShowPaiementModal(false); setSelectedFacture(null); }}
                className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-white"
              >
                Annuler
              </button>
              <button
                onClick={handlePaiement}
                disabled={!paiementData.montant}
                className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
              >
                Enregistrer
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
