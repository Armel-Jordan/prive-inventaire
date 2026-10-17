import { usePermissions } from '@/contexts/PermissionsContext';
import BoutonPiecesJointes from '@/components/BoutonPiecesJointes';
import { useState, useEffect, useCallback } from 'react';
import BoutonChampsPersonnalises from '@/components/BoutonChampsPersonnalises';
import { Plus, Eye, FileText, X, Send, Trash2, CheckCircle, XCircle } from 'lucide-react';
import BalisesFiche from '@/components/BalisesFiche';
import BoutonRemarquesRapides from '@/components/BoutonRemarquesRapides';
import { ajouterRemarque } from '@/lib/utils';
import {
  getDevis, createDevis, updateDevis, deleteDevis,
  envoyerDevis, accepterDevis, refuserDevis, convertirDevisEnCommande,
  getClientsActifs, getProduits, getConfiguration,
} from '../services/api';
import type { DevisRecord, Client } from '../services/api';
import { ApiError } from '@/services/http';
import type { Produit } from '../types';
import BoutonPdf from '@/components/BoutonPdf';
import BoutonCourriel from '@/components/BoutonCourriel';
import Pagination from '@/components/Pagination';
import { aujourdhui, dateSaisie, formatDateDocument, tauxPropose, useCodeDevise, useMontantDocument } from '@/lib/documents';
import ChampDevise from '@/components/ChampDevise';
import { useFraisAutomatiques } from '@/hooks/useFraisAutomatiques';
import FraisDocument from '@/components/FraisDocument';
import ChampsClientDePassage, { BoutonClientDePassage, DetailClientDePassage, PastilleClientDePassage } from '@/components/ClientDePassage';
import { PASSAGE_VIDE, avecClient, nomClient, saisiePassage } from '@/lib/clientDePassage';
import { useLanguage } from '@/i18n/useLanguage';

export default function DevisPage() {
  // Boutons d'action selon les permissions (rôles et permissions).
  const { peut } = usePermissions();
  const deviseEntreprise = useCodeDevise();
  const { t } = useLanguage();
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState({ dernierePage: 1, total: 0 });
  const [devisList, setDevisList] = useState<DevisRecord[]>([]);
  const [clients, setClients] = useState<Client[]>([]);
  const [produits, setProduits] = useState<Produit[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterStatut, setFilterStatut] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [selectedDevis, setSelectedDevis] = useState<DevisRecord | null>(null);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [configNumero, setConfigNumero] = useState<{ auto_increment: boolean; prefixe: string; separateur?: string; prochain_numero: number; longueur: number } | null>(null);

  const [formData, setFormData] = useState({
    client_id: '',
    date_devis: aujourdhui(),
    date_validite: '',
    notes: '',
    // Devise du devis (vide : celle de l'entreprise) et son taux ; par défaut, celle du client.
    devise: '',
    taux: '1',
  });
  // Coordonnées du client de passage, notées sur le devis.
  const [passage, setPassage] = useState(PASSAGE_VIDE);
  const clientDePassage = clients.find(c => c.id === Number(formData.client_id))?.de_passage;
  const [lignes, setLignes] = useState<{ id?: number; produit_id: number; quantite: number; prix_unitaire: number }[]>([]);
  // Frais additionnels et de livraison proposés pour le devis en cours de saisie.
  const frais = useFraisAutomatiques({
    document: 'devis',
    clientId: formData.client_id ? Number(formData.client_id) : null,
    lignes: lignes.map(l => ({ produit_id: l.produit_id, quantite: l.quantite, prix_unitaire_ht: l.prix_unitaire })),
    taux: formData.devise ? Number(formData.taux) || 1 : 1,
    actif: showModal,
  });

  const loadData = useCallback(async () => {
    try {
      setLoading(true);
      const [devisRes, clientsRes, produitsRes, config] = await Promise.all([
        getDevis({ statut: filterStatut || undefined, page }),
        getClientsActifs(),
        getProduits(),
        getConfiguration('devis').catch(() => null),
      ]);
      setDevisList(devisRes.data || []);
      setPagination({ dernierePage: devisRes.last_page ?? 1, total: devisRes.total ?? 0 });
      setClients(clientsRes || []);
      setProduits(produitsRes || []);
      if (config) setConfigNumero(config);
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }, [filterStatut, page]);

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
        // À la création : toujours envoyée (la devise de l'entreprise l'emporte alors sur celle du client).
        devise: formData.devise || deviseEntreprise,
        taux_change: formData.devise ? Number(formData.taux) : undefined,
        lignes: [...lignes, ...frais.lignes.map(f => ({ produit_id: f.produit_id, quantite: f.quantite, prix_unitaire: f.prix_unitaire_ht }))],
        // Retenues par l'API pour le client de passage seulement.
        ...passage,
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

  // Le message de l'API dit pourquoi l'action est refusée (devis expiré, déjà converti…).
  const signalerErreur = (e: unknown, defaut: string) => {
    alert(e instanceof ApiError ? ((e.data as { message?: string } | null)?.message || defaut) : defaut);
    loadData();
  };

  const handleEnvoyer = async (id: number) => {
    try {
      await envoyerDevis(id);
      loadData();
    } catch (e) { signalerErreur(e, 'Erreur lors de l\'envoi'); }
  };

  const handleAccepter = async (id: number) => {
    try {
      await accepterDevis(id);
      loadData();
    } catch (e) { signalerErreur(e, 'Erreur lors de l\'acceptation'); }
  };

  const handleRefuser = async (id: number) => {
    if (!confirm('Marquer ce devis comme refusé ?')) return;
    try {
      await refuserDevis(id);
      loadData();
    } catch (e) { signalerErreur(e, 'Erreur lors du refus'); }
  };

  const handleConvertir = async (devis: DevisRecord) => {
    if (!confirm(`Convertir le devis ${devis.numero} en commande client ?`)) return;
    try {
      const { commande } = await convertirDevisEnCommande(devis.id);
      alert(`Devis converti en commande ${commande.numero}.`);
      loadData();
    } catch (e) { signalerErreur(e, 'Erreur lors de la conversion'); }
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
    // Dates au format du champ (AAAA-MM-JJ) : l'horodatage complet de l'API vidait le champ.
    setFormData({
      client_id: String(devis.client_id),
      date_devis: dateSaisie(devis.date_devis),
      date_validite: dateSaisie(devis.date_validite),
      notes: devis.notes || '',
      devise: devis.devise ?? '',
      taux: String(devis.taux_change ?? 1),
    });
    setPassage(saisiePassage(devis));
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
      // Prix du produit proposé tant qu'aucun prix n'a été saisi (il restait à 0).
      const produit = produits.find(p => p.id === value);
      if (produit && newLignes[index].prix_unitaire === 0) {
        // Converti pour un devis en devise étrangère.
        const taux = Number(formData.taux);
        const prix = Number(produit.prix_unitaire) || 0;
        newLignes[index].prix_unitaire = formData.devise && taux > 0 ? Math.round((prix / taux) * 100) / 100 : prix;
      }
    }
    setLignes(newLignes);
  };

  const removeLigne = (index: number) => setLignes(lignes.filter((_, i) => i !== index));

  const resetForm = () => {
    setEditingId(null);
    frais.reinitialiser();
    setFormData({ client_id: '', date_devis: aujourdhui(), date_validite: '', notes: '', devise: '', taux: '1' });
    setPassage(PASSAGE_VIDE);
    setLignes([]);
  };

  // Client choisi : sa devise est proposée, avec son taux.
  const choisirClient = (id: string, liste: Client[] = clients) => {
    const deviseClient = liste.find(c => c.id === Number(id))?.devise ?? '';
    setFormData(f => ({ ...f, client_id: id, devise: deviseClient, taux: deviseClient ? '' : '1' }));
    if (deviseClient) tauxPropose(deviseClient, deviseEntreprise).then(taux => setFormData(f => (f.devise === deviseClient ? { ...f, taux } : f)));
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

  const fmtDate = (d: string) => formatDateDocument(d);
  // Montants dans la devise de chaque document (celle de l'entreprise, ou celle d'un client étranger).
  const montantDoc = useMontantDocument();
  const montant = (v: number | string | null | undefined, deviseDoc?: string | null) => montantDoc(v, deviseDoc);
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
          onChange={(e) => { setFilterStatut(e.target.value); setPage(1); }}
          aria-label="Statut"
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
                  <td className="px-4 py-3 font-mono text-sm">
                    {devis.numero}
                    <BalisesFiche entite="devis" entiteId={devis.id} />
                  </td>
                  <td className="px-4 py-3 text-sm">{nomClient(devis)}<PastilleClientDePassage client={devis.client} /></td>
                  <td className="px-4 py-3 text-sm">{fmtDate(devis.date_devis)}</td>
                  <td className="px-4 py-3 text-sm">{fmtDate(devis.date_validite)}</td>
                  <td className="px-4 py-3 text-right text-sm font-medium whitespace-nowrap">{montant(devis.montant_total, devis.devise)}</td>
                  <td className="px-4 py-3 text-center">
                    {getStatutBadge(devis.statut)}
                    {devis.commande && <span className="block mt-1 text-xs text-gray-500" title="Commande issue de ce devis">→ {devis.commande.numero}</span>}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex justify-center gap-1">
                      <BoutonPiecesJointes entite="devis" entiteId={devis.id} titre={devis.numero} taille={16} />
                      <button onClick={() => { setSelectedDevis(devis); setShowDetailModal(true); }} className="p-1 text-gray-600 hover:bg-gray-100 rounded" title="Voir"><Eye size={16} /></button>
                      <BoutonPdf chemin={`/devis/${devis.id}/pdf`} nomFichier={`devis-${devis.numero}.pdf`} taille={16} />
                      <BoutonCourriel type="devis" documentId={devis.id} taille={16} />
                      {devis.statut === 'brouillon' && (
                        <>
                          <button onClick={() => openEdit(devis)} className="p-1 text-blue-600 hover:bg-blue-50 rounded" title="Modifier"><FileText size={16} /></button>
                          {peut('devis', 'envoyer') && (
                            <button onClick={() => handleEnvoyer(devis.id)} className="p-1 text-indigo-600 hover:bg-indigo-50 rounded" title="Envoyer"><Send size={16} /></button>
                          )}
                          <button onClick={() => handleDelete(devis.id)} className="p-1 text-red-600 hover:bg-red-50 rounded" title="Supprimer"><Trash2 size={16} /></button>
                        </>
                      )}
                      {devis.statut === 'envoye' && (
                        <>
                          {peut('devis', 'decider') && (
                            <button onClick={() => handleAccepter(devis.id)} className="p-1 text-green-600 hover:bg-green-50 rounded" title="Accepter"><CheckCircle size={16} /></button>
                          )}
                          {peut('devis', 'decider') && (
                            <button onClick={() => handleRefuser(devis.id)} className="p-1 text-red-600 hover:bg-red-50 rounded" title="Refuser"><XCircle size={16} /></button>
                          )}
                        </>
                      )}
                      {devis.statut === 'accepte' && !devis.commande && (
                        <>{peut('devis', 'convertir') && (
                          <button onClick={() => handleConvertir(devis)} className="p-1 text-green-600 hover:bg-green-50 rounded" title="Convertir en commande"><FileText size={16} /></button>
                        )}</>
                      )}
                      <BoutonChampsPersonnalises entite="devis" entiteId={devis.id} titre={devis.numero} taille={16} />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <Pagination page={page} dernierePage={pagination.dernierePage} total={pagination.total} onPage={setPage} />
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
                Numéro : {configNumero.prefixe}{configNumero.prefixe ? configNumero.separateur ?? '' : ''}{String(configNumero.prochain_numero).padStart(configNumero.longueur, '0')} (auto-généré)
              </p>
            )}
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Client *</label>
                  <select required value={formData.client_id} onChange={(e) => choisirClient(e.target.value)} className="w-full px-3 py-2 border rounded-lg">
                    <option value="">Sélectionner...</option>
                    {clients.map(c => <option key={c.id} value={c.id}>{c.raison_sociale}</option>)}
                  </select>
                  {!clientDePassage && <BoutonClientDePassage onChoisir={c => { setClients(cs => avecClient(cs, c)); choisirClient(String(c.id), [c]); }} />}
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
                  <BoutonRemarquesRapides onInserer={texte => setFormData(f => ({ ...f, notes: ajouterRemarque(f.notes, texte, ' ') }))} />
                </div>
              </div>
              {clientDePassage && <ChampsClientDePassage valeur={passage} onChange={setPassage} />}
              {editingId ? (formData.devise && <p className="text-sm text-gray-500">{t.currency.label} : {formData.devise} (1 {formData.devise} = {formData.taux} {deviseEntreprise})</p>) : (
                <ChampDevise valeur={{ devise: formData.devise, taux: formData.taux }} onChange={v => setFormData(f => ({ ...f, devise: v.devise, taux: v.taux }))} />
              )}

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
                        <span className="text-sm text-gray-600 w-28 text-right whitespace-nowrap">{montant(ligne.quantite * ligne.prix_unitaire, formData.devise || null)}</span>
                        <button type="button" onClick={() => removeLigne(idx)} className="text-red-500" aria-label="Retirer la ligne" title="Retirer la ligne"><X size={16} /></button>
                      </div>
                    ))}
                    <FraisDocument frais={frais} montant={v => montant(v, formData.devise || null)} />
                    <div className="text-right font-medium">Total HT : {montant(totalLignes + frais.total, formData.devise || null)}</div>
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
                <div><span className="text-gray-500">Client:</span><p className="font-medium">{nomClient(selectedDevis)}<PastilleClientDePassage client={selectedDevis.client} /></p><DetailClientDePassage document={selectedDevis} /></div>
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
                        <span className="whitespace-nowrap">{montant(l.montant_ligne, selectedDevis?.devise)}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}
              <div className="border-t pt-3 flex justify-between font-bold">
                <span>Total HT :</span>
                <span>{montant(selectedDevis.montant_total, selectedDevis.devise)}</span>
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
