import { useState, useEffect, useCallback } from 'react';
import { Plus, Eye, FileText, X, Send, Trash2, CheckCircle, XCircle } from 'lucide-react';
import {
  getDevis, createDevis, updateDevis, deleteDevis,
  envoyerDevis, accepterDevis, refuserDevis, convertirDevisEnCommande,
  getClientsActifs, getProduits, getConfiguration,
} from '../services/api';
import type { DevisRecord, Client } from '../services/api';
import type { Produit } from '../types';

export default function DevisPage() {
  const [devisList, setDevisList] = useState<DevisRecord[]>([]);
  const [clients, setClients] = useState<Client[]>([]);
  const [produits, setProduits] = useState<Produit[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterStatut, setFilterStatut] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [selectedDevis, setSelectedDevis] = useState<DevisRecord | null>(null);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [configNumero, setConfigNumero] = useState<{ auto_increment: boolean; prefixe: string; prochain_numero: number; longueur: number } | null>(null);

  const [formData, setFormData] = useState({
    client_id: '',
    date_devis: new Date().toISOString().split('T')[0],
    date_validite: '',
    notes: '',
  });
  const [lignes, setLignes] = useState<{ id?: number; produit_id: number; quantite: number; prix_unitaire: number }[]>([]);

  const loadData = useCallback(async () => {
    try {
      setLoading(true);
      const [devisRes, clientsRes, produitsRes, config] = await Promise.all([
        getDevis({ statut: filterStatut || undefined }),
        getClientsActifs(),
        getProduits(),
        getConfiguration('devis').catch(() => null),
      ]);
      setDevisList(devisRes.data || []);
      setClients(clientsRes || []);
      setProduits(produitsRes || []);
      if (config) setConfigNumero(config);
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
    if (lignes.length === 0) { alert('Ajoutez au moins une ligne'); return; }
    if (lignes.some(l => l.produit_id === 0)) { alert('Sélectionnez un produit pour chaque ligne'); return; }

    try {
      const payload = {
        client_id: parseInt(formData.client_id),
        date_devis: formData.date_devis,
        date_validite: formData.date_validite,
        notes: formData.notes || undefined,
        lignes,
      };
      if (editingId) {
        await updateDevis(editingId, payload);
      } else {
        await createDevis(payload);
      }
      setShowModal(false);
      resetForm();
      loadData();
    } catch (error) {
      console.error('Erreur:', error);
      alert('Erreur lors de la sauvegarde');
    }
  };

  const handleEnvoyer = async (id: number) => {
    try {
      await envoyerDevis(id);
      loadData();
    } catch { alert('Erreur'); }
  };

  const handleAccepter = async (id: number) => {
    try {
      await accepterDevis(id);
      loadData();
    } catch { alert('Erreur'); }
  };

  const handleRefuser = async (id: number) => {
    if (!confirm('Marquer ce devis comme refusé ?')) return;
    try {
      await refuserDevis(id);
      loadData();
    } catch { alert('Erreur'); }
  };

  const handleConvertir = async (devis: DevisRecord) => {
    if (!confirm(`Convertir le devis ${devis.numero} en commande client ?`)) return;
    try {
      await convertirDevisEnCommande(devis.id);
      alert('Devis converti en commande !');
      loadData();
    } catch { alert('Erreur lors de la conversion'); }
  };

  const handleDelete = async (id: number) => {
    if (!confirm('Supprimer ce devis ?')) return;
    try {
      await deleteDevis(id);
      loadData();
    } catch { alert('Erreur lors de la suppression'); }
  };

  const openEdit = (devis: DevisRecord) => {
    setEditingId(devis.id);
    setFormData({
      client_id: String(devis.client_id),
      date_devis: devis.date_devis,
      date_validite: devis.date_validite,
      notes: devis.notes || '',
    });
    setLignes(devis.lignes.map(l => ({
      id: l.id,
      produit_id: l.produit_id,
      quantite: l.quantite,
      prix_unitaire: Number(l.prix_unitaire),
    })));
    setShowModal(true);
  };

  const addLigne = () => setLignes([...lignes, { produit_id: 0, quantite: 1, prix_unitaire: 0 }]);

  const updateLigne = (index: number, field: string, value: number) => {
    const newLignes = [...lignes];
    (newLignes[index] as Record<string, number>)[field] = value;
    if (field === 'produit_id') {
      const produit = produits.find(p => p.id === value);
      if (produit && newLignes[index].prix_unitaire === 0) {
        newLignes[index].prix_unitaire = 0;
      }
    }
    setLignes(newLignes);
  };

  const removeLigne = (index: number) => setLignes(lignes.filter((_, i) => i !== index));

  const resetForm = () => {
    setEditingId(null);
    setFormData({ client_id: '', date_devis: new Date().toISOString().split('T')[0], date_validite: '', notes: '' });
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
      brouillon: 'Brouillon', envoye: 'Envoyé', accepte: 'Accepté', refuse: 'Refusé', expire: 'Expiré',
    };
    return <span className={`px-2 py-1 text-xs rounded-full ${styles[statut] || 'bg-gray-100'}`}>{labels[statut] || statut}</span>;
  };

  const fmtDate = (d: string) => d ? new Date(d).toLocaleDateString('fr-FR') : '-';
  const totalLignes = lignes.reduce((sum, l) => sum + l.quantite * l.prix_unitaire, 0);

  return (
    <div>
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold text-gray-800">Devis</h1>
        <button
          onClick={() => { resetForm(); setShowModal(true); }}
          className="flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700"
        >
          <Plus size={20} />
          Nouveau devis
        </button>
      </div>

      <div className="bg-white rounded-xl shadow-sm p-4 mb-6">
        <select
          value={filterStatut}
          onChange={(e) => setFilterStatut(e.target.value)}
          className="px-3 py-2 border rounded-lg"
        >
          <option value="">Tous les statuts</option>
          <option value="brouillon">Brouillon</option>
          <option value="envoye">Envoyé</option>
          <option value="accepte">Accepté</option>
          <option value="refuse">Refusé</option>
          <option value="expire">Expiré</option>
        </select>
      </div>

      <div className="bg-white rounded-xl shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-8 text-center text-gray-500">Chargement...</div>
        ) : devisList.length === 0 ? (
          <div className="p-8 text-center text-gray-500">Aucun devis trouvé</div>
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50 border-b">
              <tr>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">N°</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Client</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Date</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Validité</th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">Montant</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600">Statut</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {devisList.map((devis) => (
                <tr key={devis.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3 font-mono text-sm">{devis.numero}</td>
                  <td className="px-4 py-3 text-sm">{devis.client?.raison_sociale}</td>
                  <td className="px-4 py-3 text-sm">{fmtDate(devis.date_devis)}</td>
                  <td className="px-4 py-3 text-sm">{fmtDate(devis.date_validite)}</td>
                  <td className="px-4 py-3 text-right text-sm font-medium">{Number(devis.montant_total).toFixed(2)} €</td>
                  <td className="px-4 py-3 text-center">{getStatutBadge(devis.statut)}</td>
                  <td className="px-4 py-3">
                    <div className="flex justify-center gap-1">
                      <button onClick={() => { setSelectedDevis(devis); setShowDetailModal(true); }} className="p-1 text-gray-600 hover:bg-gray-100 rounded" title="Voir"><Eye size={16} /></button>
                      {devis.statut === 'brouillon' && (
                        <>
                          <button onClick={() => openEdit(devis)} className="p-1 text-blue-600 hover:bg-blue-50 rounded" title="Modifier"><FileText size={16} /></button>
                          <button onClick={() => handleEnvoyer(devis.id)} className="p-1 text-indigo-600 hover:bg-indigo-50 rounded" title="Envoyer"><Send size={16} /></button>
                          <button onClick={() => handleDelete(devis.id)} className="p-1 text-red-600 hover:bg-red-50 rounded" title="Supprimer"><Trash2 size={16} /></button>
                        </>
                      )}
                      {devis.statut === 'envoye' && (
                        <>
                          <button onClick={() => handleAccepter(devis.id)} className="p-1 text-green-600 hover:bg-green-50 rounded" title="Accepter"><CheckCircle size={16} /></button>
                          <button onClick={() => handleRefuser(devis.id)} className="p-1 text-red-600 hover:bg-red-50 rounded" title="Refuser"><XCircle size={16} /></button>
                        </>
                      )}
                      {devis.statut === 'accepte' && (
                        <button onClick={() => handleConvertir(devis)} className="p-1 text-green-600 hover:bg-green-50 rounded" title="Convertir en commande"><FileText size={16} /></button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {/* Modal création/édition */}
      {showModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl p-6 w-full max-w-3xl max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-xl font-bold">{editingId ? 'Modifier le devis' : 'Nouveau devis'}</h2>
              <button onClick={() => { setShowModal(false); resetForm(); }}><X size={20} /></button>
            </div>
            {configNumero?.auto_increment && !editingId && (
              <p className="text-sm text-gray-500 mb-4">
                Numéro : {configNumero.prefixe}{String(configNumero.prochain_numero).padStart(configNumero.longueur, '0')} (auto-généré)
              </p>
            )}
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Client *</label>
                  <select required value={formData.client_id} onChange={(e) => setFormData({ ...formData, client_id: e.target.value })} className="w-full px-3 py-2 border rounded-lg">
                    <option value="">Sélectionner...</option>
                    {clients.map(c => <option key={c.id} value={c.id}>{c.raison_sociale}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Date devis *</label>
                  <input type="date" required value={formData.date_devis} onChange={(e) => setFormData({ ...formData, date_devis: e.target.value })} className="w-full px-3 py-2 border rounded-lg" />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Date validité *</label>
                  <input type="date" required value={formData.date_validite} onChange={(e) => setFormData({ ...formData, date_validite: e.target.value })} className="w-full px-3 py-2 border rounded-lg" />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Notes</label>
                  <input type="text" value={formData.notes} onChange={(e) => setFormData({ ...formData, notes: e.target.value })} className="w-full px-3 py-2 border rounded-lg" placeholder="Optionnel" />
                </div>
              </div>

              <div>
                <div className="flex justify-between items-center mb-2">
                  <label className="text-sm font-medium">Lignes</label>
                  <button type="button" onClick={addLigne} className="text-sm text-purple-600 hover:underline">+ Ajouter ligne</button>
                </div>
                {lignes.length === 0 ? (
                  <p className="text-sm text-gray-500 italic">Aucune ligne.</p>
                ) : (
                  <div className="space-y-2">
                    {lignes.map((ligne, idx) => (
                      <div key={idx} className="flex gap-2 items-center">
                        <select value={ligne.produit_id} onChange={(e) => updateLigne(idx, 'produit_id', parseInt(e.target.value))} className="flex-1 px-2 py-1 border rounded text-sm">
                          <option value={0}>Produit...</option>
                          {produits.map(p => <option key={p.id} value={p.id}>{p.description}</option>)}
                        </select>
                        <input type="number" min="1" value={ligne.quantite} onChange={(e) => updateLigne(idx, 'quantite', parseInt(e.target.value) || 1)} className="w-20 px-2 py-1 border rounded text-sm" placeholder="Qté" />
                        <input type="number" step="0.01" value={ligne.prix_unitaire} onChange={(e) => updateLigne(idx, 'prix_unitaire', parseFloat(e.target.value) || 0)} className="w-24 px-2 py-1 border rounded text-sm" placeholder="Prix" />
                        <span className="text-sm text-gray-600 w-20 text-right">{(ligne.quantite * ligne.prix_unitaire).toFixed(2)} €</span>
                        <button type="button" onClick={() => removeLigne(idx)} className="text-red-500"><X size={16} /></button>
                      </div>
                    ))}
                    <div className="text-right font-medium">Total: {totalLignes.toFixed(2)} €</div>
                  </div>
                )}
              </div>

              <div className="flex justify-end gap-3 mt-4">
                <button type="button" onClick={() => { setShowModal(false); resetForm(); }} className="px-4 py-2 border rounded-lg hover:bg-gray-50">Annuler</button>
                <button type="submit" className="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">{editingId ? 'Modifier' : 'Créer'}</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal détail */}
      {showDetailModal && selectedDevis && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-xl font-bold">Devis {selectedDevis.numero}</h2>
              <button onClick={() => setShowDetailModal(false)}><X size={20} /></button>
            </div>
            <div className="space-y-3">
              <div className="grid grid-cols-2 gap-4 text-sm">
                <div><span className="text-gray-500">Client:</span><p className="font-medium">{selectedDevis.client?.raison_sociale}</p></div>
                <div><span className="text-gray-500">Statut:</span><p className="mt-1">{getStatutBadge(selectedDevis.statut)}</p></div>
                <div><span className="text-gray-500">Date:</span><p>{fmtDate(selectedDevis.date_devis)}</p></div>
                <div><span className="text-gray-500">Validité:</span><p>{fmtDate(selectedDevis.date_validite)}</p></div>
              </div>
              {selectedDevis.lignes?.length > 0 && (
                <div className="border-t pt-3">
                  <p className="text-sm font-medium mb-2">Lignes :</p>
                  <div className="space-y-1">
                    {selectedDevis.lignes.map((l, i) => (
                      <div key={i} className="flex justify-between text-sm">
                        <span>{l.produit?.description || `Produit #${l.produit_id}`} × {l.quantite}</span>
                        <span>{Number(l.montant_ligne).toFixed(2)} €</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}
              <div className="border-t pt-3 flex justify-between font-bold">
                <span>Total:</span>
                <span>{Number(selectedDevis.montant_total).toFixed(2)} €</span>
              </div>
              {selectedDevis.notes && <p className="text-sm text-gray-500">Notes: {selectedDevis.notes}</p>}
            </div>
            <div className="flex justify-end mt-4">
              <button onClick={() => setShowDetailModal(false)} className="px-4 py-2 border rounded-lg hover:bg-gray-50">Fermer</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
