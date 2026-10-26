import { usePermissions } from '@/contexts/PermissionsContext';
import BoutonPiecesJointes from '@/components/BoutonPiecesJointes';
import { useState, useEffect, useCallback } from 'react';
import { useRechercheAdresse } from '@/hooks/useRechercheAdresse';
import BoutonChampsPersonnalises from '@/components/BoutonChampsPersonnalises';
import { Plus, Eye, FileText, X, Send, Trash2, CheckCircle, XCircle, Link2, Copy, BellRing, Receipt, CopyPlus, Search } from 'lucide-react';
import BalisesFiche from '@/components/BalisesFiche';
import BoutonRemarquesRapides from '@/components/BoutonRemarquesRapides';
import { ajouterRemarque } from '@/lib/utils';
import {
  getDevis, createDevis, updateDevis, deleteDevis,
  envoyerDevis, accepterDevis, refuserDevis, convertirDevisEnCommande, lienReponseDevis, facturerDevis, copierDevis,
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
import { usePrixAutomatiques } from '@/hooks/usePrixAutomatiques';
import { useReferencesClient } from '@/hooks/useReferencesClient';
import OriginePrix from '@/components/OriginePrix';
import { appliquerPrix, changerUnite, choisirProduit, dansLaDevise, saisirPrix, type LignePrix } from '@/lib/prixDocuments';
import { annexesALaVente, prixUnite, quantiteAvecUnite, uniteParDefaut } from '@/lib/unitesVente';
import ChoixUnite from '@/components/ChoixUnite';
import ChampsClientDePassage, { BoutonClientDePassage, DetailClientDePassage, PastilleClientDePassage } from '@/components/ClientDePassage';
import { PASSAGE_VIDE, avecClient, nomClient, saisiePassage } from '@/lib/clientDePassage';
import { useLanguage } from '@/i18n/useLanguage';
import { useVerrou } from '@/hooks/useVerrou';
import BandeauVerrou from '@/components/BandeauVerrou';
import ChoixRepresentant from '@/components/ChoixRepresentant';
import { useRepresentants } from '@/hooks/useRepresentants';

export default function DevisPage() {
  // Boutons d'action selon les permissions (rôles et permissions).
  const { peut, canCreate } = usePermissions();
  const deviseEntreprise = useCodeDevise();
  const { t, language } = useLanguage();
  const tr = t.quoteAnswer;
  // Lien de réponse en ligne affiché pour être transmis au client.
  const [lienReponse, setLienReponse] = useState<{ numero: string; url: string; copie: boolean } | null>(null);
  const dateHeure = (iso: string) => new Date(iso).toLocaleString(language === 'en' ? 'en-US' : 'fr-FR', { dateStyle: 'short', timeStyle: 'short' });
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState({ dernierePage: 1, total: 0 });
  const [devisList, setDevisList] = useState<DevisRecord[]>([]);
  const [clients, setClients] = useState<Client[]>([]);
  const [produits, setProduits] = useState<Produit[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterStatut, setFilterStatut] = useState('');
  // Représentants commerciaux (phase 1.11a) : filtre de la liste, et celui du devis (par défaut, celui du client).
  const representants = useRepresentants();
  const [filterRepresentant, setFilterRepresentant] = useState<number | null>(null);
  const [representantId, setRepresentantId] = useState<number | null>(null);
  // Recherche par numéro ou client, pré-remplie par « ?recherche= » (recherche globale, autres écrans).
  const [search, setSearch] = useRechercheAdresse();
  const [recherche, setRecherche] = useState(() => search.trim());
  const [showModal, setShowModal] = useState(false);
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [selectedDevis, setSelectedDevis] = useState<DevisRecord | null>(null);
  const [editingId, setEditingId] = useState<number | null>(null);
  // Devis verrouillé pendant sa modification : un autre compte voit qui le modifie.
  const verrou = useVerrou('devis', showModal ? editingId : null);
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
  // Référence du produit chez le client choisi, à côté de sa désignation (phase 1.2c).
  const referencesClient = useReferencesClient(formData.client_id ? Number(formData.client_id) : null);
  const avecReference = (produitId?: number) => {
    const reference = produitId ? referencesClient.get(produitId) : undefined;
    return reference ? ` — ${t.clientFile.references.short.replace('{ref}', reference)}` : '';
  };
  const [lignes, setLignes] = useState<(LignePrix & { id?: number; prix_unitaire: number })[]>([]);
  // Frais additionnels et de livraison proposés pour le devis en cours de saisie.
  const frais = useFraisAutomatiques({
    document: 'devis',
    clientId: formData.client_id ? Number(formData.client_id) : null,
    lignes: lignes.map(l => ({ produit_id: l.produit_id, quantite: l.quantite, prix_unitaire_ht: l.prix_unitaire })),
    taux: formData.devise ? Number(formData.taux) || 1 : 1,
    actif: showModal,
  });
  // Prix du client (liste, remise, règles) proposés sur les lignes dont le prix n'a pas été saisi.
  usePrixAutomatiques({
    clientId: formData.client_id ? Number(formData.client_id) : null,
    date: formData.date_devis,
    taux: formData.devise ? Number(formData.taux) || 1 : 1,
    lignes,
    actif: showModal,
    appliquer: prix => setLignes(ls => appliquerPrix(ls, prix, 'prix_unitaire', p => dansLaDevise(p, formData.devise, Number(formData.taux)), t.pricing.simulator.origins)),
  });

  // La recherche part 300 ms après la dernière frappe, et non à chaque caractère.
  useEffect(() => {
    const minuterie = setTimeout(() => { setRecherche(search.trim()); setPage(1); }, 300);
    return () => clearTimeout(minuterie);
  }, [search]);

  const loadData = useCallback(async () => {
    try {
      setLoading(true);
      const [devisRes, clientsRes, produitsRes, config] = await Promise.all([
        getDevis({ statut: filterStatut || undefined, search: recherche || undefined, representant_id: filterRepresentant ?? undefined, page }),
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
  }, [filterStatut, recherche, filterRepresentant, page]);

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
        lignes: [
          ...lignes.map(({ id, produit_id, quantite, prix_unitaire, unite }) => ({ id, produit_id, quantite, prix_unitaire, unite: unite ?? null })),
          ...frais.lignes.map(f => ({ produit_id: f.produit_id, quantite: f.quantite, prix_unitaire: f.prix_unitaire_ht })),
        ],
        // Retenues par l'API pour le client de passage seulement.
        ...passage,
        representant_id: representantId,
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

  // Facture brouillon du devis accepté, sans commande (un devis ne se convertit qu'une fois).
  const handleFacturer = async (devis: DevisRecord) => {
    if (!confirm(t.quoteActions.confirmInvoice.replace('{numero}', devis.numero))) return;
    try {
      const { message } = await facturerDevis(devis.id, aujourdhui());
      alert(message);
      loadData();
    } catch (e) { signalerErreur(e, 'Erreur lors de la facturation'); }
  };

  // Copie en nouveau brouillon, ouverte aussitôt pour être ajustée.
  const handleCopier = async (devis: DevisRecord) => {
    try {
      const { devis: copie } = await copierDevis(devis.id);
      loadData();
      openEdit(copie);
    } catch (e) { signalerErreur(e, 'Erreur lors de la copie'); }
  };

  const handleConvertir = async (devis: DevisRecord) => {
    if (!confirm(`Convertir le devis ${devis.numero} en commande client ?`)) return;
    try {
      const { commande } = await convertirDevisEnCommande(devis.id, aujourdhui());
      alert(`Devis converti en commande ${commande.numero}.`);
      loadData();
    } catch (e) { signalerErreur(e, 'Erreur lors de la conversion'); }
  };

  // Lien où le client répond au devis ; le partager revient à l'envoyer.
  const handleLienReponse = async (devis: DevisRecord) => {
    try {
      const { url } = await lienReponseDevis(devis.id);
      setLienReponse({ numero: devis.numero, url, copie: false });
      loadData();
    } catch (e) { signalerErreur(e, 'Lien de réponse indisponible'); }
  };

  const copierLien = async () => {
    if (!lienReponse) return;
    try {
      await navigator.clipboard.writeText(lienReponse.url);
      setLienReponse({ ...lienReponse, copie: true });
    } catch {
      // Copie refusée par le navigateur : le lien reste sélectionnable dans le champ.
    }
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
    setRepresentantId(devis.representant_id ?? null);
    setLignes(devis.lignes.map(l => ({
      id: l.id,
      produit_id: l.produit_id,
      quantite: l.quantite,
      prix_unitaire: Number(l.prix_unitaire),
      unite: l.unite ?? null,
    })));
    setShowModal(true);
  };

  const addLigne = () => setLignes([...lignes, { produit_id: 0, quantite: 1, prix_unitaire: 0, auto: true }]);

  const updateLigne = (index: number, field: 'produit_id' | 'quantite' | 'prix_unitaire', value: number) => {
    setLignes(ls => {
      const maj = ls.map((ligne, i) => {
        if (i !== index) return ligne;
        if (field === 'prix_unitaire') return saisirPrix(ligne, 'prix_unitaire', value);
        if (field === 'quantite') return { ...ligne, quantite: value };
        // Prix du produit proposé tant qu'aucun prix n'a été saisi (converti pour un devis en devise étrangère),
        // remplacé ensuite par celui du client ; dans l'unité de vente proposée d'office pour ce produit.
        const produit = produits.find(p => p.id === value);
        const unite = uniteParDefaut(produit);
        return { ...choisirProduit(ligne, value, 'prix_unitaire', dansLaDevise(prixUnite(produit, unite), formData.devise, Number(formData.taux))), unite };
      });
      if (field !== 'produit_id') return maj;
      // Annexes du produit choisi (la pile fournie avec l'appareil), ajoutées d'office après sa ligne.
      const produit = produits.find(p => p.id === value);
      const annexes = annexesALaVente(produit, ls[index]?.quantite ?? 1, uniteParDefaut(produit), produits).map(a => ({
        produit_id: Number(a.produit.id), quantite: a.quantite, unite: null,
        prix_unitaire: dansLaDevise(prixUnite(a.produit, null), formData.devise, Number(formData.taux)), auto: true,
      }));
      return [...maj.slice(0, index + 1), ...annexes, ...maj.slice(index + 1)];
    });
  };

  // Unité de la ligne : le prix de cette unité est proposé, sauf prix saisi.
  const changerUniteLigne = (index: number, unite: string | null) => setLignes(ls => ls.map((ligne, i) => (i !== index ? ligne
    : changerUnite(ligne, unite, 'prix_unitaire', dansLaDevise(prixUnite(produits.find(p => p.id === ligne.produit_id), unite), formData.devise, Number(formData.taux))))));

  const removeLigne = (index: number) => setLignes(lignes.filter((_, i) => i !== index));

  const resetForm = () => {
    setEditingId(null);
    frais.reinitialiser();
    setFormData({ client_id: '', date_devis: aujourdhui(), date_validite: '', notes: '', devise: '', taux: '1' });
    setPassage(PASSAGE_VIDE);
    setRepresentantId(null);
    setLignes([]);
  };

  // Client choisi : sa devise est proposée, avec son taux ; son représentant aussi.
  const choisirClient = (id: string, liste: Client[] = clients) => {
    const deviseClient = liste.find(c => c.id === Number(id))?.devise ?? '';
    setRepresentantId(liste.find(c => c.id === Number(id))?.representant_id ?? null);
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

      <div className="bg-white rounded-xl shadow-sm p-4 mb-6 flex flex-wrap gap-4">
        <div className="relative flex-1 min-w-[200px]">
          <Search size={20} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input
            type="text"
            placeholder="Rechercher par numéro ou client..."
            aria-label="Rechercher un devis"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full pl-10 pr-4 py-2 border rounded-lg"
          />
        </div>
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
        <ChoixRepresentant value={filterRepresentant} onChange={id => { setFilterRepresentant(id); setPage(1); }} representants={representants}
          libelleVide={t.salesReps.all} ariaLabel={t.salesReps.label} className="px-3 py-2 border rounded-lg" />
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
                    {devis.reponse_en_ligne && <span className="block mt-1 text-xs text-gray-500">{tr.online}</span>}
                    {devis.commande && <span className="block mt-1 text-xs text-gray-500" title="Commande issue de ce devis">→ {devis.commande.numero}</span>}
                    {devis.facture && <span className="block mt-1 text-xs text-gray-500" title={t.quoteActions.invoicedAs}>→ {devis.facture.numero}</span>}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex justify-center gap-1">
                      <BoutonPiecesJointes entite="devis" entiteId={devis.id} titre={devis.numero} taille={16} />
                      <button onClick={() => { setSelectedDevis(devis); setShowDetailModal(true); }} className="p-1 text-gray-600 hover:bg-gray-100 rounded" title="Voir"><Eye size={16} /></button>
                      <BoutonPdf chemin={`/devis/${devis.id}/pdf`} nomFichier={`devis-${devis.numero}.pdf`} taille={16} />
                      <BoutonCourriel type="devis" documentId={devis.id} taille={16} />
                      {['brouillon', 'envoye'].includes(devis.statut) && peut('devis', 'envoyer') && (
                        <button onClick={() => handleLienReponse(devis)} className="p-1 text-teal-600 hover:bg-teal-50 rounded" title={tr.shareLinkHelp} aria-label={`${tr.shareLink} — ${devis.numero}`}><Link2 size={16} /></button>
                      )}
                      {devis.statut === 'envoye' && peut('devis', 'envoyer') && (
                        <BoutonCourriel type="relance_devis" documentId={devis.id} taille={16} titre={tr.remind} icone={BellRing} onFerme={loadData} />
                      )}
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
                      {devis.statut === 'accepte' && !devis.commande && !devis.facture && peut('devis', 'convertir') && (
                        <>
                          <button onClick={() => handleConvertir(devis)} className="p-1 text-green-600 hover:bg-green-50 rounded" title="Convertir en commande"><FileText size={16} /></button>
                          {canCreate('factures') && (
                            <button onClick={() => handleFacturer(devis)} className="p-1 text-purple-600 hover:bg-purple-50 rounded" title={t.quoteActions.invoiceHelp} aria-label={`${t.quoteActions.invoice} — ${devis.numero}`}><Receipt size={16} /></button>
                          )}
                        </>
                      )}
                      {canCreate('devis') && (
                        <button onClick={() => handleCopier(devis)} className="p-1 text-gray-600 hover:bg-gray-100 rounded" title={t.quoteActions.copyHelp} aria-label={`${t.quoteActions.copy} — ${devis.numero}`}><CopyPlus size={16} /></button>
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
            <BandeauVerrou verrou={verrou} />
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
                <div>
                  <label htmlFor="devis-representant" className="block text-sm font-medium mb-1">{t.salesReps.label}</label>
                  <ChoixRepresentant id="devis-representant" value={representantId} onChange={setRepresentantId} representants={representants} />
                  <p className="text-xs text-gray-500 mt-1">{t.salesReps.documentHint}</p>
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
                          {produits.map(p => <option key={p.id} value={p.id}>{p.description}{avecReference(p.id)}</option>)}
                        </select>
                        <input type="number" min="1" value={ligne.quantite} onChange={(e) => updateLigne(idx, 'quantite', parseInt(e.target.value) || 1)} className="w-20 px-2 py-1 border rounded text-sm" placeholder="Qté" />
                        <ChoixUnite produit={produits.find(p => p.id === ligne.produit_id)} valeur={ligne.unite} onChange={u => changerUniteLigne(idx, u)}
                          libelle={t.productUnits.lineUnit.replace('{n}', String(idx + 1))} />
                        <input type="number" step="0.01" value={ligne.prix_unitaire} onChange={(e) => updateLigne(idx, 'prix_unitaire', parseFloat(e.target.value) || 0)} className="w-24 px-2 py-1 border rounded text-sm" placeholder="Prix" />
                        <span className="text-sm text-gray-600 w-28 text-right whitespace-nowrap">{montant(ligne.quantite * ligne.prix_unitaire, formData.devise || null)}</span>
                        <OriginePrix info={ligne.info_prix} />
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
                <button type="submit" disabled={verrou.bloque}
                  className="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:cursor-not-allowed disabled:opacity-50">{editingId ? 'Modifier' : 'Créer'}</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Lien de réponse en ligne, à transmettre au client */}
      {lienReponse && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" role="dialog" aria-modal="true" aria-label={tr.linkTitle.replace('{numero}', lienReponse.numero)}>
          <div className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-lg">
            <div className="flex items-center justify-between mb-3">
              <h2 className="text-lg font-bold dark:text-white">{tr.linkTitle.replace('{numero}', lienReponse.numero)}</h2>
              <button onClick={() => setLienReponse(null)} aria-label={tr.close}><X size={20} /></button>
            </div>
            <p className="text-sm text-gray-600 dark:text-gray-300 mb-3">{tr.linkHelp}</p>
            <div className="flex gap-2">
              <input readOnly value={lienReponse.url} onFocus={e => e.target.select()} aria-label={tr.shareLink}
                className="flex-1 min-w-0 px-3 py-2 border rounded-lg text-sm font-mono dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
              <button type="button" onClick={copierLien} className="flex items-center gap-1 px-3 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">
                <Copy size={14} /> {tr.copy}
              </button>
            </div>
            {lienReponse.copie && <p className="text-xs text-green-700 dark:text-green-400 mt-2" role="status">{tr.copied}</p>}
            <div className="flex justify-end mt-4">
              <button onClick={() => setLienReponse(null)} className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:text-white">{tr.close}</button>
            </div>
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
                {selectedDevis.representant && <div><span className="text-gray-500">{t.salesReps.label} :</span><p>{selectedDevis.representant.nom}</p></div>}
              </div>
              {selectedDevis.lignes?.length > 0 && (
                <div className="border-t pt-3">
                  <p className="text-sm font-medium mb-2">Lignes :</p>
                  <div className="space-y-1">
                    {selectedDevis.lignes.map((l, i) => (
                      <div key={i} className="flex justify-between text-sm">
                        <span>{l.produit?.description || `Produit #${l.produit_id}`} × {quantiteAvecUnite(l.quantite, l.unite)}</span>
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
              {(selectedDevis.envoye_le || selectedDevis.reponse_le || selectedDevis.derniere_relance_le) && (
                <div className="border-t pt-3 text-sm space-y-1">
                  {selectedDevis.envoye_le && <p className="text-gray-500">{tr.sentOn.replace('{date}', dateHeure(selectedDevis.envoye_le))}</p>}
                  {(selectedDevis.relances ?? 0) > 0 && selectedDevis.derniere_relance_le && (
                    <p className="text-gray-500">{tr.reminders.replace('{n}', String(selectedDevis.relances)).replace('{date}', dateHeure(selectedDevis.derniere_relance_le))}</p>
                  )}
                  {selectedDevis.reponse_le && ['accepte', 'refuse'].includes(selectedDevis.statut) && (
                    <p className="font-medium">
                      {(selectedDevis.statut === 'accepte'
                        ? (selectedDevis.reponse_en_ligne ? tr.acceptedOnline : tr.acceptedManually)
                        : (selectedDevis.reponse_en_ligne ? tr.declinedOnline : tr.declinedManually)).replace('{date}', dateHeure(selectedDevis.reponse_le))}
                      {selectedDevis.reponse_nom && ` ${tr.by.replace('{nom}', selectedDevis.reponse_nom)}`}
                    </p>
                  )}
                  {selectedDevis.reponse_commentaire && <p className="text-gray-600"><span className="text-gray-500">{tr.comment} :</span> {selectedDevis.reponse_commentaire}</p>}
                </div>
              )}
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
