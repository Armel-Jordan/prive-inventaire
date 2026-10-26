import { usePermissions } from '@/contexts/PermissionsContext';
import BoutonPiecesJointes from '@/components/BoutonPiecesJointes';
import { useState, useEffect, useCallback } from 'react';
import { useRechercheAdresse } from '@/hooks/useRechercheAdresse';
import BoutonChampsPersonnalises from '@/components/BoutonChampsPersonnalises';
import { Link } from 'react-router-dom';
import { ArchiveRestore, Eye, CreditCard, FileText, Pencil, ReceiptText, Send, Trash2, Plus, X, Search } from 'lucide-react';
import BalisesFiche from '@/components/BalisesFiche';
import BoutonRemarquesRapides from '@/components/BoutonRemarquesRapides';
import { ajouterRemarque } from '@/lib/utils';
import {
  getFactures,
  getFacture,
  emettreFacture,
  enregistrerPaiement,
  creerBonLivraison,
  deleteFacture,
  createFacture,
  updateFacture,
  getClientsActifs,
  getProduits,
  getReferentiel,
  getEscompteFacture,
} from '../services/api';
import type { Facture, Client, FicheReferentiel, PropositionEscompte } from '../services/api';
import type { Produit } from '../types';
import RecapitulatifTaxes from '@/components/RecapitulatifTaxes';
import BoutonPdf from '@/components/BoutonPdf';
import BoutonCourriel from '@/components/BoutonCourriel';
import Pagination from '@/components/Pagination';
import { signaler } from '@/lib/erreurs';
import { aujourdhui, dateSaisie, formatDateDocument, tauxPropose, useCodeDevise, useMontantDocument } from '@/lib/documents';
import ChampDevise from '@/components/ChampDevise';
import EditeurNoteCredit from '@/components/avoirs/EditeurNoteCredit';
import { appliquerAcompte, getAcomptes, type Acompte } from '@/services/acomptes';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';
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
import { useVerrou } from '@/hooks/useVerrou';
import BandeauVerrou from '@/components/BandeauVerrou';
import { useLanguage } from '@/i18n/useLanguage';
import ChoixRepresentant from '@/components/ChoixRepresentant';
import { useRepresentants } from '@/hooks/useRepresentants';
import ChoixTransporteur from '@/components/ChoixTransporteur';
import { lienSuivi, transporteursActifs, useTransporteurs } from '@/hooks/useTransporteurs';
import { useEncaissementCaisse } from '@/hooks/useEncaissementCaisse';
import CaisseDuPoste from '@/components/caisse/CaisseDuPoste';
import ChampCarteCadeau from '@/components/cartesCadeaux/ChampCarteCadeau';


export default function FacturesPage() {
  // Boutons d'action selon les permissions (rôles et permissions).
  const { peut, canCreate } = usePermissions();
  // Montants dans la devise de chaque facture (celle de l'entreprise, ou celle d'un client étranger).
  const montantDoc = useMontantDocument();
  const montant = (v: number | string | null | undefined, deviseDoc?: string | null) => montantDoc(v, deviseDoc);
  const deviseEntreprise = useCodeDevise();
  const { t, language } = useLanguage();
  const { toasts, toast, dismiss } = useToast();
  // Poste de caisse : le paiement entre dans la session ouverte de sa caisse (ouverture proposée si elle est fermée).
  const encaissement = useEncaissementCaisse();
  // Facture en cours de crédit (note de crédit).
  const [aCrediter, setACrediter] = useState<number | null>(null);
  // Acomptes disponibles du client de la facture à payer.
  const [acomptesClient, setAcomptesClient] = useState<Acompte[]>([]);
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState({ dernierePage: 1, total: 0 });
  const [factures, setFactures] = useState<Facture[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterStatut, setFilterStatut] = useState('');
  // Représentants commerciaux (phase 1.11a) : filtre de la liste, et celui de la facture (par défaut, celui du client).
  const representants = useRepresentants();
  const [filterRepresentant, setFilterRepresentant] = useState<number | null>(null);
  const [representantId, setRepresentantId] = useState<number | null>(null);
  // Transporteur (phase 1.11b) : celui du client par défaut ; champs masqués sans transporteur actif.
  const transporteurs = useTransporteurs();
  const [filterTransporteur, setFilterTransporteur] = useState<number | null>(null);
  const [transporteurId, setTransporteurId] = useState<number | null>(null);
  const [numeroSuivi, setNumeroSuivi] = useState('');
  // Recherche par numéro ou client, pré-remplie par « ?recherche= » (recherche globale, abonnements, autres écrans).
  const [search, setSearch] = useRechercheAdresse();
  const [recherche, setRecherche] = useState(() => search.trim());
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [showPaiementModal, setShowPaiementModal] = useState(false);
  const [selectedFacture, setSelectedFacture] = useState<Facture | null>(null);
  const [paiementData, setPaiementData] = useState({
    montant: '',
    date_paiement: aujourdhui(),
    mode_paiement: 'virement',
    reference: '',
    // Paiement par carte cadeau : son numéro.
    carte_cadeau: '',
  });
  const [showCreateModal, setShowCreateModal] = useState(false);
  // Facture brouillon en modification (null : nouvelle facture), verrouillée tant que le formulaire est ouvert.
  const [editingId, setEditingId] = useState<number | null>(null);
  const verrou = useVerrou('facture', showCreateModal ? editingId : null);
  const [clients, setClients] = useState<Client[]>([]);
  const [produits, setProduits] = useState<Produit[]>([]);
  const [createForm, setCreateForm] = useState({
    client_id: '',
    date_facture: aujourdhui(),
    date_echeance: '',
    notes: '',
    condition_paiement_id: '',
    // Devise de la facture (vide : celle de l'entreprise) et son taux ; par défaut, celle du client.
    devise: '',
    taux: '1',
  });
  // Conditions de paiement du référentiel ; escompte possible pour le paiement en cours.
  const [conditions, setConditions] = useState<FicheReferentiel[]>([]);
  const [escompte, setEscompte] = useState<PropositionEscompte | null>(null);
  const [avecEscompte, setAvecEscompte] = useState(false);
  const [createLignes, setCreateLignes] = useState<(LignePrix & { prix_unitaire_ht: number })[]>([]);
  // Coordonnées du client de passage, notées sur la facture.
  const [passage, setPassage] = useState(PASSAGE_VIDE);
  const clientDePassage = clients.find(c => c.id === Number(createForm.client_id))?.de_passage;
  // Référence du produit chez le client choisi, à côté de sa désignation (phase 1.2c).
  const referencesClient = useReferencesClient(createForm.client_id ? Number(createForm.client_id) : null);
  const avecReference = (produitId?: number) => {
    const reference = produitId ? referencesClient.get(produitId) : undefined;
    return reference ? ` — ${t.clientFile.references.short.replace('{ref}', reference)}` : '';
  };
  // Frais additionnels et de livraison proposés pour la facture en cours de saisie.
  const frais = useFraisAutomatiques({
    document: 'facture',
    clientId: createForm.client_id ? Number(createForm.client_id) : null,
    lignes: createLignes,
    taux: createForm.devise ? Number(createForm.taux) || 1 : 1,
    actif: showCreateModal,
  });
  // Prix du client (liste, remise, règles) proposés sur les lignes dont le prix n'a pas été saisi.
  usePrixAutomatiques({
    clientId: createForm.client_id ? Number(createForm.client_id) : null,
    date: createForm.date_facture,
    taux: createForm.devise ? Number(createForm.taux) || 1 : 1,
    lignes: createLignes,
    actif: showCreateModal,
    appliquer: prix => setCreateLignes(ls => appliquerPrix(ls, prix, 'prix_unitaire_ht', p => dansLaDevise(p, createForm.devise, Number(createForm.taux)), t.pricing.simulator.origins)),
  });
  const modifierLigne = (index: number, modification: (ligne: LignePrix & { prix_unitaire_ht: number }) => LignePrix & { prix_unitaire_ht: number }) =>
    setCreateLignes(ls => ls.map((ligne, i) => (i === index ? modification(ligne) : ligne)));

  const loadMeta = async () => {
    const [c, p, conds] = await Promise.all([getClientsActifs(), getProduits(), getReferentiel('conditions_paiement', true).catch(() => [])]);
    setClients(c || []);
    setProduits(p || []);
    setConditions(conds || []);
  };

  // Escompte pour paiement rapide proposé pour la date du paiement (condition de paiement de la facture).
  const proposerEscompte = (facture: Facture, date: string) => {
    setEscompte(null);
    setAvecEscompte(false);
    if (!facture.condition_paiement_id) return;
    getEscompteFacture(facture.id, date).then(setEscompte, () => {});
  };

  // La recherche part 300 ms après la dernière frappe, et non à chaque caractère.
  useEffect(() => {
    const minuterie = setTimeout(() => { setRecherche(search.trim()); setPage(1); }, 300);
    return () => clearTimeout(minuterie);
  }, [search]);

  const loadFactures = useCallback(async () => {
    try {
      setLoading(true);
      const response = await getFactures({ statut: filterStatut || undefined, recherche: recherche || undefined, representant_id: filterRepresentant ?? undefined, transporteur_id: filterTransporteur ?? undefined, page });
      setFactures(response.data || []);
      setPagination({ dernierePage: response.last_page ?? 1, total: response.total ?? 0 });
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }, [filterStatut, recherche, filterRepresentant, filterTransporteur, page]);

  useEffect(() => {
    loadFactures();
  }, [loadFactures]);

  // Clients et produits du formulaire : une fois, et non à chaque page ou filtre.
  useEffect(() => {
    loadMeta().catch(() => {});
  }, []);

  const handleVoirDetail = async (id: number) => {
    try {
      const facture = await getFacture(id);
      setSelectedFacture(facture);
      setShowDetailModal(true);
    } catch (error) {
      signaler(error, 'Impossible de charger le détail de la facture');
    }
  };

  const handleEmettre = async (id: number) => {
    try {
      await emettreFacture(id);
      loadFactures();
    } catch (error) {
      signaler(error, 'Erreur lors de l\'émission de la facture');
    }
  };

  const handleCreerBL = async (id: number) => {
    try {
      const bon = await creerBonLivraison(id);
      alert(t.invoiceDelivery.created.replace('{numero}', bon.numero));
      loadFactures();
    } catch (error) {
      signaler(error, t.invoiceDelivery.error);
    }
  };

  const handleDelete = async (id: number) => {
    if (!confirm('Supprimer cette facture ?')) return;
    try {
      await deleteFacture(id);
      loadFactures();
    } catch (error) {
      signaler(error, 'Impossible de supprimer cette facture.');
    }
  };

  // Client choisi : sa devise est proposée, avec son taux ; son représentant aussi.
  const choisirClient = (id: string, liste: Client[] = clients) => {
    const deviseClient = liste.find(c => c.id === Number(id))?.devise ?? '';
    setRepresentantId(liste.find(c => c.id === Number(id))?.representant_id ?? null);
    setTransporteurId(liste.find(c => c.id === Number(id))?.transporteur_id ?? null);
    setCreateForm(f => ({ ...f, client_id: id, devise: deviseClient, taux: deviseClient ? '' : '1' }));
    if (deviseClient) tauxPropose(deviseClient, deviseEntreprise).then(taux => setCreateForm(f => (f.devise === deviseClient ? { ...f, taux } : f)));
  };

  const fermerFormulaire = () => {
    frais.reinitialiser();
    setShowCreateModal(false);
    setEditingId(null);
    setCreateForm({ client_id: '', date_facture: aujourdhui(), date_echeance: '', notes: '', condition_paiement_id: '', devise: '', taux: '1' });
    setPassage(PASSAGE_VIDE);
    setRepresentantId(null);
    setTransporteurId(null);
    setNumeroSuivi('');
    setCreateLignes([]);
  };

  // Brouillon à corriger : le formulaire de création, rempli avec la facture (ses lignes de frais comprises : un frais
  // déjà sur le document n'est pas proposé de nouveau).
  const ouvrirModification = async (id: number) => {
    try {
      const facture = await getFacture(id);
      setEditingId(facture.id);
      setCreateForm({
        client_id: String(facture.client_id),
        date_facture: dateSaisie(facture.date_facture),
        date_echeance: dateSaisie(facture.date_echeance ?? ''),
        notes: facture.notes ?? '',
        condition_paiement_id: facture.condition_paiement_id ? String(facture.condition_paiement_id) : '',
        devise: facture.devise ?? '',
        taux: String(facture.taux_change ?? 1),
      });
      setPassage(saisiePassage(facture));
      setRepresentantId(facture.representant_id ?? null);
      setTransporteurId(facture.transporteur_id ?? null);
      setNumeroSuivi(facture.numero_suivi ?? '');
      setCreateLignes((facture.lignes ?? []).map(l => ({
        produit_id: l.produit_id,
        quantite: l.quantite,
        prix_unitaire_ht: Number(l.prix_unitaire_ht),
        unite: l.unite ?? null,
      })));
      setShowCreateModal(true);
    } catch (error) {
      signaler(error, t.invoiceEdit.loadError);
    }
  };

  const handleCreateFacture = async (e: React.FormEvent) => {
    e.preventDefault();
    if (createLignes.length === 0) { alert('Ajoutez au moins une ligne'); return; }
    try {
      const saisie = {
        client_id: parseInt(createForm.client_id),
        date_facture: createForm.date_facture,
        date_echeance: createForm.date_echeance || undefined,
        condition_paiement_id: createForm.condition_paiement_id ? Number(createForm.condition_paiement_id) : undefined,
        // Toujours envoyée : la devise de l'entreprise choisie pour un client étranger l'emporte sur la sienne.
        devise: createForm.devise || deviseEntreprise,
        taux_change: createForm.devise ? Number(createForm.taux) : undefined,
        notes: createForm.notes || undefined,
        lignes: [
          ...createLignes.map(l => ({ produit_id: l.produit_id, quantite: l.quantite, prix_unitaire_ht: l.prix_unitaire_ht, unite: l.unite ?? null })),
          ...frais.lignes.map(l => ({ produit_id: l.produit_id, quantite: l.quantite, prix_unitaire_ht: l.prix_unitaire_ht })),
        ],
        // Retenues par l'API pour le client de passage seulement.
        ...passage,
        representant_id: representantId,
        transporteur_id: transporteurId,
        numero_suivi: numeroSuivi.trim() || null,
      };
      if (editingId) await updateFacture(editingId, saisie);
      else await createFacture(saisie);
      fermerFormulaire();
      loadFactures();
    } catch (error) {
      signaler(error, editingId ? t.invoiceEdit.saveError : 'Erreur lors de la création de la facture');
    }
  };

  const ouvrirPaiement = (facture: Facture) => {
    setSelectedFacture(facture);
    // Sur un poste de caisse, l'argent comptant est proposé.
    setPaiementData({ ...paiementData, montant: String(facture.reste_a_payer ?? ''), ...(encaissement.caisse ? { mode_paiement: 'especes' } : {}) });
    setShowPaiementModal(true);
    proposerEscompte(facture, paiementData.date_paiement);
    setAcomptesClient([]);
    if (peut('acomptes', 'appliquer')) {
      // Un acompte réservé à une autre commande n'est pas proposé (il appartient à cette commande).
      getAcomptes({ client_id: facture.client_id, disponibilite: 'avec_solde', per_page: 20 })
        .then(r => setAcomptesClient((r.data ?? []).filter(a => (!a.commande_id || a.commande_id === facture.commande_id)
          // Seulement dans la devise de la facture.
          && (a.devise ?? null) === (facture.devise ?? null))), () => {});
    }
  };

  // Paie la facture avec un acompte du client, au plus ce qui en reste et ce qui reste dû.
  const appliquerDepuisPaiement = async (acompte: Acompte) => {
    if (!selectedFacture) return;
    try {
      await appliquerAcompte(acompte.id, { facture_id: selectedFacture.id, montant: Math.min(acompte.disponible, Number(selectedFacture.reste_a_payer)), date: aujourdhui() });
      toast(t.deposits.applyDone.replace('{numero}', selectedFacture.numero));
      setShowPaiementModal(false);
      setSelectedFacture(null);
      loadFactures();
    } catch (error) {
      signaler(error, t.deposits.error);
    }
  };

  const handlePaiement = async () => {
    if (!selectedFacture || !paiementData.montant) return;
    try {
      await encaissement.encaisser(caisse_id => enregistrerPaiement(selectedFacture.id, {
        montant: parseFloat(paiementData.montant),
        date_paiement: paiementData.date_paiement,
        mode_paiement: paiementData.mode_paiement,
        reference: paiementData.reference || undefined,
        escompte: avecEscompte || undefined,
        caisse_id,
        carte_cadeau: paiementData.mode_paiement === 'carte_cadeau' ? paiementData.carte_cadeau.trim() : undefined,
      }));
      setShowPaiementModal(false);
      setPaiementData({
        montant: '',
        date_paiement: aujourdhui(),
        mode_paiement: 'virement',
        reference: '',
        carte_cadeau: '',
      });
      setSelectedFacture(null);
      loadFactures();
    } catch (error) {
      signaler(error, 'Erreur lors de l\'enregistrement du paiement');
    }
  };

  const getStatutBadge = (statut: string) => {
    const styles: Record<string, string> = {
      brouillon: 'bg-gray-100 text-gray-800',
      emise: 'bg-blue-100 text-blue-800',
      partiellement_payee: 'bg-yellow-100 text-yellow-800',
      payee: 'bg-green-100 text-green-800',
      creditee: 'bg-purple-100 text-purple-800',
      annulee: 'bg-red-100 text-red-800',
    };
    const labels: Record<string, string> = {
      brouillon: 'Brouillon',
      emise: 'Émise',
      partiellement_payee: 'Partiel',
      payee: 'Payée',
      creditee: 'Créditée',
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
        <div className="flex flex-wrap gap-4">
          <div className="relative flex-1 min-w-[200px]">
            <Search size={20} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <input
              type="text"
              placeholder="Rechercher par numéro ou client..."
              aria-label="Rechercher une facture"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="w-full pl-10 pr-4 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
            />
          </div>
          <select
            value={filterStatut}
            onChange={(e) => { setFilterStatut(e.target.value); setPage(1); }}
            aria-label="Filtrer par statut"
            className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          >
            <option value="">Tous les statuts</option>
            <option value="brouillon">Brouillon</option>
            <option value="emise">Émise</option>
            <option value="partiellement_payee">Partiellement payée</option>
            <option value="payee">Payée</option>
            <option value="creditee">Créditée</option>
          </select>
          <ChoixRepresentant value={filterRepresentant} onChange={id => { setFilterRepresentant(id); setPage(1); }} representants={representants}
            libelleVide={t.salesReps.all} ariaLabel={t.salesReps.label} className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
          {transporteursActifs(transporteurs) && (
            <ChoixTransporteur value={filterTransporteur} onChange={id => { setFilterTransporteur(id); setPage(1); }} transporteurs={transporteurs}
              libelleVide={t.carriers.all} ariaLabel={t.carriers.label} className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
          )}
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
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Montant TTC</th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Reste à payer</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Statut</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
              {factures.map((facture) => (
                <tr key={facture.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                  <td className="px-4 py-3 font-mono text-sm text-gray-900 dark:text-white">
                    {facture.numero}
                    <BalisesFiche entite="facture" entiteId={facture.id} />
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{nomClient(facture)}<PastilleClientDePassage client={facture.client} /></td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{formatDateDocument(facture.date_facture)}</td>
                  <td className="px-4 py-3 text-right text-sm font-medium text-gray-900 dark:text-white">
                    {montant(facture.montant_ttc, facture.devise)}
                  </td>
                  <td className="px-4 py-3 text-right text-sm">
                    <span className={Number(facture.reste_a_payer) > 0 ? 'text-red-600 font-medium' : 'text-green-600'}>
                      {montant(Number(facture.reste_a_payer ?? 0), facture.devise)}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-center">{getStatutBadge(facture.statut)}</td>
                  <td className="px-4 py-3">
                    <div className="flex justify-center gap-1">
                      <BoutonPiecesJointes entite="facture" entiteId={facture.id} titre={facture.numero} />
                      <button
                        onClick={() => handleVoirDetail(facture.id)}
                        className="p-1 text-gray-600 hover:bg-gray-100 rounded"
                        title="Voir détails"
                      >
                        <Eye size={18} />
                      </button>
                      <BoutonPdf chemin={`/factures/${facture.id}/pdf`} nomFichier={`facture-${facture.numero}.pdf`} />
                      <BoutonCourriel type="facture" documentId={facture.id} />
                      {facture.statut === 'brouillon' && (
                        <>
                          {peut('factures', 'modifier') && (
                            <button
                              onClick={() => ouvrirModification(facture.id)}
                              className="p-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 rounded"
                              title={t.invoiceEdit.edit}
                              aria-label={`${t.invoiceEdit.edit} — ${facture.numero}`}
                            >
                              <Pencil size={18} />
                            </button>
                          )}
                          {peut('factures', 'emettre') && (
                            <button
                              onClick={() => handleEmettre(facture.id)}
                              className="p-1 text-blue-600 hover:bg-blue-50 rounded"
                              title="Émettre"
                            >
                              <Send size={18} />
                            </button>
                          )}
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
                        <>{peut('factures', 'encaisser') && (
                          <button
                            onClick={() => ouvrirPaiement(facture)}
                            className="p-1 text-green-600 hover:bg-green-50 rounded"
                            title="Enregistrer paiement"
                          >
                            <CreditCard size={18} />
                          </button>
                        )}</>
                      )}
                      {/* Note de crédit : retour, erreur ou geste commercial sur une facture émise, payée ou non. */}
                      {['emise', 'partiellement_payee', 'payee'].includes(facture.statut) && (
                        <>{peut('avoirs', 'creer') && (
                          <button
                            onClick={() => setACrediter(facture.id)}
                            className="p-1 text-indigo-600 hover:bg-indigo-50 rounded"
                            title={t.creditNotes.credit}
                            aria-label={`${t.creditNotes.credit} — ${facture.numero}`}
                          >
                            <ReceiptText size={18} />
                          </button>
                        )}</>
                      )}
                      {/* Retour de marchandise : remise en stock ou rebut, sur une facture émise (même créditée). */}
                      {['emise', 'partiellement_payee', 'payee', 'creditee'].includes(facture.statut) && peut('retours_clients', 'creer') && (
                        <Link
                          to={`/retours-clients?facture=${facture.id}`}
                          className="p-1 text-teal-600 hover:bg-teal-50 rounded"
                          title={t.customerReturns.returnGoods}
                          aria-label={`${t.customerReturns.returnGoods} — ${facture.numero}`}
                        >
                          <ArchiveRestore size={18} />
                        </Link>
                      )}
                      {/* Une facture émise, même payée, se livre avec un bon ; après une livraison partielle, un nouveau bon
                          livre le reste (sans refacturer). Le bouton disparaît tant qu'un bon est en cours. */}
                      {['emise', 'partiellement_payee', 'payee'].includes(facture.statut)
                        && (!facture.bon_livraison || facture.bon_livraison.statut === 'livre_partiel') && (
                        <>{canCreate('bons_livraison') && (
                          <button
                            onClick={() => handleCreerBL(facture.id)}
                            className="p-1 text-purple-600 hover:bg-purple-50 rounded"
                            title={facture.bon_livraison ? t.invoiceDelivery.remainderHint : t.invoiceDelivery.create}
                            aria-label={`${facture.bon_livraison ? t.invoiceDelivery.remainder : t.invoiceDelivery.create} — ${facture.numero}`}
                          >
                            <FileText size={18} />
                          </button>
                        )}</>
                      )}
                      <BoutonChampsPersonnalises entite="facture" entiteId={facture.id} titre={facture.numero} />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <Pagination page={page} dernierePage={pagination.dernierePage} total={pagination.total} onPage={setPage} />
      </div>

      {/* Modal détail */}
      {showDetailModal && selectedFacture && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <h2 className="text-xl font-bold mb-4 dark:text-white">
              Facture {selectedFacture.numero}
            </h2>
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <span className="text-sm text-gray-500">Client:</span>
                  <p className="font-medium dark:text-white">{nomClient(selectedFacture)}<PastilleClientDePassage client={selectedFacture.client} /></p>
                  <DetailClientDePassage document={selectedFacture} />
                </div>
                <div>
                  <span className="text-sm text-gray-500">Statut:</span>
                  <p>{getStatutBadge(selectedFacture.statut)}</p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Date facture:</span>
                  <p className="dark:text-white">{formatDateDocument(selectedFacture.date_facture)}</p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Date échéance:</span>
                  <p className="dark:text-white">{formatDateDocument(selectedFacture.date_echeance)}</p>
                </div>
                {selectedFacture.transporteur && (
                  <div>
                    <span className="text-sm text-gray-500">{t.carriers.label} :</span>
                    <p className="dark:text-white">
                      {selectedFacture.transporteur.nom}
                      {selectedFacture.numero_suivi && <span className="ml-2 text-xs text-gray-500">{t.carriers.tracking} : {selectedFacture.numero_suivi}</span>}
                      {lienSuivi(selectedFacture.transporteur.url_suivi, selectedFacture.numero_suivi) && (
                        <a href={lienSuivi(selectedFacture.transporteur.url_suivi, selectedFacture.numero_suivi) ?? undefined} target="_blank" rel="noopener noreferrer"
                          className="ml-2 text-xs text-blue-600 hover:underline">{t.carriers.track}</a>
                      )}
                    </p>
                  </div>
                )}
                {selectedFacture.representant && (
                  <div>
                    <span className="text-sm text-gray-500">{t.salesReps.label} :</span>
                    <p className="dark:text-white">
                      {selectedFacture.representant.nom}
                      {selectedFacture.taux_commission !== null && selectedFacture.taux_commission !== undefined && (
                        <span className="ml-2 text-xs text-gray-500">{t.salesReps.commission.replace('{taux}', Number(selectedFacture.taux_commission).toLocaleString(language === 'en' ? 'en-US' : 'fr-FR'))}</span>
                      )}
                    </p>
                  </div>
                )}
                {selectedFacture.condition_paiement && (
                  <div>
                    <span className="text-sm text-gray-500">Condition de paiement:</span>
                    <p className="dark:text-white">{selectedFacture.condition_paiement.libelle}</p>
                  </div>
                )}
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
                          <td className="py-1">
                            {ligne.produit?.description ?? `Produit #${ligne.produit_id}`}
                            {ligne.description && <span className="block text-xs text-gray-500">{ligne.description}</span>}
                          </td>
                          <td className="py-1 text-right">{quantiteAvecUnite(ligne.quantite, ligne.unite)}</td>
                          <td className="py-1 text-right">{montant(Number(ligne.prix_unitaire_ht ?? 0), selectedFacture?.devise)}</td>
                          <td className="py-1 text-right">{montant(Number(ligne.montant_ht ?? 0), selectedFacture?.devise)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              <div className="border-t pt-4 dark:border-gray-700">
                <div className="flex justify-between">
                  <span className="text-gray-500">Total HT:</span>
                  <span className="dark:text-white">{montant(selectedFacture.montant_ht, selectedFacture?.devise)}</span>
                </div>
                {/* Détail par taxe quand la facture utilise des groupes de taxes, sinon la TVA globale. */}
                {selectedFacture.recapitulatif_taxes && selectedFacture.recapitulatif_taxes.length > 0 ? (
                  <RecapitulatifTaxes recapitulatif={selectedFacture.recapitulatif_taxes} devise={selectedFacture.devise} />
                ) : (
                  <div className="flex justify-between">
                    <span className="text-gray-500">TVA:</span>
                    <span className="dark:text-white">{montant(selectedFacture.montant_tva, selectedFacture?.devise)}</span>
                  </div>
                )}
                <div className="flex justify-between font-bold text-lg">
                  <span className="dark:text-white">Total TTC:</span>
                  <span className="dark:text-white">{montant(selectedFacture.montant_ttc, selectedFacture?.devise)}</span>
                </div>
                <div className="flex justify-between mt-2">
                  <span className="text-gray-500">Payé:</span>
                  <span className="text-green-600">{montant(Number(selectedFacture.montant_paye ?? 0), selectedFacture?.devise)}</span>
                </div>
                {Number(selectedFacture.montant_escompte ?? 0) > 0 && (
                  <div className="flex justify-between">
                    <span className="text-gray-500">Escompte:</span>
                    <span className="text-green-600">{montant(Number(selectedFacture.montant_escompte), selectedFacture?.devise)}</span>
                  </div>
                )}
                {Number(selectedFacture.montant_credite ?? 0) > 0 && (
                  <div className="flex justify-between">
                    <span className="text-gray-500">Crédité:</span>
                    <span className="text-indigo-600">{montant(Number(selectedFacture.montant_credite), selectedFacture?.devise)}</span>
                  </div>
                )}
                <div className="flex justify-between font-medium">
                  <span className="text-gray-500">Reste à payer:</span>
                  <span className={Number(selectedFacture.reste_a_payer) > 0 ? 'text-red-600' : 'text-green-600'}>
                    {montant(Number(selectedFacture.reste_a_payer ?? 0), selectedFacture?.devise)}
                  </span>
                </div>
              </div>

              {selectedFacture.avoirs && selectedFacture.avoirs.length > 0 && (
                <div className="border-t pt-4 dark:border-gray-700">
                  <h3 className="font-medium mb-2 dark:text-white">{t.creditNotes.creditedOn}</h3>
                  <ul className="space-y-1 text-sm">
                    {selectedFacture.avoirs.map(avoir => (
                      <li key={avoir.id} className="flex justify-between gap-2">
                        <Link to={`/notes-credit?recherche=${encodeURIComponent(avoir.numero ?? '')}`} className="font-mono text-indigo-600 hover:underline">
                          {avoir.numero ?? t.creditNotes.draft}
                        </Link>
                        <span className="text-gray-500">{formatDateDocument(avoir.date_avoir)} · {t.creditNotes.statuses[avoir.statut] ?? avoir.statut}</span>
                        <span className="dark:text-white">{montant(avoir.montant_ttc, selectedFacture?.devise)}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {selectedFacture.retours && selectedFacture.retours.length > 0 && (
                <div className="border-t pt-4 dark:border-gray-700">
                  <h3 className="font-medium mb-2 dark:text-white">{t.customerReturns.invoiceReturns}</h3>
                  <ul className="space-y-1 text-sm">
                    {selectedFacture.retours.map(retour => (
                      <li key={retour.id} className="flex justify-between gap-2">
                        <Link to={`/retours-clients?recherche=${encodeURIComponent(retour.numero ?? selectedFacture.numero ?? '')}`} className="font-mono text-teal-600 hover:underline">
                          {retour.numero ?? t.customerReturns.draft}
                        </Link>
                        <span className="text-gray-500">{formatDateDocument(retour.date_retour)} · {t.customerReturns.statuses[retour.statut] ?? retour.statut}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {selectedFacture.echeances && selectedFacture.echeances.length > 0 && (
                <div className="border-t pt-4 dark:border-gray-700">
                  <h3 className="font-medium mb-2 dark:text-white">Échéances</h3>
                  <div className="space-y-2">
                    {selectedFacture.echeances.map((ech, idx) => (
                      <div key={idx} className="flex justify-between text-sm">
                        <span className="dark:text-gray-300">{formatDateDocument(ech.date_echeance)}</span>
                        <span className="dark:text-white">{montant(Number(ech.montant ?? 0), selectedFacture?.devise)}</span>
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
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-3xl max-h-[90vh] overflow-y-auto">
            <div className="flex justify-between items-center mb-4">
              <h2 className="text-xl font-bold dark:text-white">{editingId ? t.invoiceEdit.title : 'Nouvelle facture'}</h2>
              <button onClick={fermerFormulaire}><X size={20} className="text-gray-500" /></button>
            </div>
            <BandeauVerrou verrou={verrou} />
            <form onSubmit={handleCreateFacture} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Client *</label>
                  <select
                    required
                    value={createForm.client_id}
                    onChange={(e) => choisirClient(e.target.value)}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  >
                    <option value="">Sélectionner...</option>
                    {clients.map(c => <option key={c.id} value={c.id}>{c.raison_sociale}</option>)}
                  </select>
                  {!clientDePassage && <BoutonClientDePassage onChoisir={c => { setClients(cs => avecClient(cs, c)); choisirClient(String(c.id), [c]); }} />}
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
                  <span className="text-xs text-gray-500">Vide : selon la condition de paiement.</span>
                </div>
                <ChampDevise className="col-span-2" valeur={{ devise: createForm.devise, taux: createForm.taux }}
                  onChange={v => setCreateForm(f => ({ ...f, devise: v.devise, taux: v.taux }))} />
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Condition de paiement</label>
                  <select value={createForm.condition_paiement_id}
                    onChange={(e) => setCreateForm({ ...createForm, condition_paiement_id: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                    <option value="">Celle du client (ou par défaut)</option>
                    {conditions.map(c => <option key={c.id} value={c.id}>{String(c.libelle)}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Notes</label>
                  <input type="text" value={createForm.notes}
                    onChange={(e) => setCreateForm({ ...createForm, notes: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                  <BoutonRemarquesRapides onInserer={texte => setCreateForm(f => ({ ...f, notes: ajouterRemarque(f.notes, texte, ' ') }))} />
                </div>
                <div>
                  <label htmlFor="facture-representant" className="block text-sm font-medium mb-1 dark:text-gray-300">{t.salesReps.label}</label>
                  <ChoixRepresentant id="facture-representant" value={representantId} onChange={setRepresentantId} representants={representants} />
                  <span className="text-xs text-gray-500">{t.salesReps.documentHint}</span>
                </div>
                {(transporteursActifs(transporteurs) || transporteurId !== null) && (
                  <div>
                    <label htmlFor="facture-transporteur" className="block text-sm font-medium mb-1 dark:text-gray-300">{t.carriers.label}</label>
                    <ChoixTransporteur id="facture-transporteur" value={transporteurId} onChange={setTransporteurId} transporteurs={transporteurs} />
                    <span className="text-xs text-gray-500">{t.carriers.documentHint}</span>
                  </div>
                )}
                {(transporteursActifs(transporteurs) || transporteurId !== null || numeroSuivi !== '') && (
                  <div>
                    <label htmlFor="facture-suivi" className="block text-sm font-medium mb-1 dark:text-gray-300">{t.carriers.tracking}</label>
                    <input id="facture-suivi" type="text" maxLength={60} value={numeroSuivi} onChange={e => setNumeroSuivi(e.target.value)}
                      className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                    <span className="text-xs text-gray-500">{t.carriers.trackingHint}</span>
                  </div>
                )}
              </div>
              {clientDePassage && <ChampsClientDePassage valeur={passage} onChange={setPassage} />}
              <div>
                <div className="flex justify-between items-center mb-2">
                  <label className="text-sm font-medium dark:text-gray-300">Lignes</label>
                  <button type="button"
                    onClick={() => setCreateLignes([...createLignes, { produit_id: 0, quantite: 1, prix_unitaire_ht: 0, auto: true }])}
                    className="text-sm text-purple-600 hover:underline">+ Ajouter ligne</button>
                </div>
                {createLignes.length === 0 ? (
                  <p className="text-sm text-gray-500 italic">Aucune ligne.</p>
                ) : (
                  <div className="space-y-2">
                    {createLignes.map((l, idx) => (
                      <div key={idx} className="flex gap-2 items-center">
                        <select value={l.produit_id}
                          onChange={(e) => {
                            // Prix du produit proposé tant qu'aucun prix n'a été saisi (converti pour une facture en devise
                            // étrangère), remplacé ensuite par celui du client.
                            const produitId = parseInt(e.target.value);
                            const produit = produits.find(p => p.id === produitId);
                            // Dans l'unité de vente proposée d'office pour ce produit.
                            const unite = uniteParDefaut(produit);
                            // Ses annexes (la pile fournie avec l'appareil), ajoutées d'office après sa ligne.
                            const annexes = annexesALaVente(produit, l.quantite, unite, produits).map(a => ({
                              produit_id: Number(a.produit.id), quantite: a.quantite, unite: null,
                              prix_unitaire_ht: dansLaDevise(prixUnite(a.produit, null), createForm.devise, Number(createForm.taux)), auto: true,
                            }));
                            setCreateLignes(ls => {
                              const maj = ls.map((ligne, i) => (i !== idx ? ligne
                                : { ...choisirProduit(ligne, produitId, 'prix_unitaire_ht', dansLaDevise(prixUnite(produit, unite), createForm.devise, Number(createForm.taux))), unite }));
                              return [...maj.slice(0, idx + 1), ...annexes, ...maj.slice(idx + 1)];
                            });
                          }}
                          aria-label="Produit"
                          className="flex-1 px-2 py-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                          <option value={0}>Produit...</option>
                          {produits.map(p => <option key={p.id} value={p.id}>{p.description}{avecReference(p.id)}</option>)}
                        </select>
                        <input type="number" min="1" value={l.quantite}
                          onChange={(e) => modifierLigne(idx, ligne => ({ ...ligne, quantite: parseInt(e.target.value) || 1 }))}
                          className="w-20 px-2 py-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white" placeholder="Qté" />
                        <ChoixUnite produit={produits.find(p => p.id === l.produit_id)} valeur={l.unite} libelle={t.productUnits.lineUnit.replace('{n}', String(idx + 1))}
                          onChange={u => modifierLigne(idx, ligne => changerUnite(ligne, u, 'prix_unitaire_ht',
                            dansLaDevise(prixUnite(produits.find(p => p.id === ligne.produit_id), u), createForm.devise, Number(createForm.taux))))} />
                        <input type="number" step="0.01" value={l.prix_unitaire_ht}
                          onChange={(e) => modifierLigne(idx, ligne => saisirPrix(ligne, 'prix_unitaire_ht', parseFloat(e.target.value) || 0))}
                          className="w-24 px-2 py-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white" placeholder="Prix HT" />
                        <span className="text-sm text-gray-600 dark:text-gray-400 w-20 text-right">
                          {montant((l.quantite * l.prix_unitaire_ht), createForm.devise || null)}
                        </span>
                        <OriginePrix info={l.info_prix} />
                        <button type="button" onClick={() => setCreateLignes(createLignes.filter((_, i) => i !== idx))}
                          className="text-red-500 hover:text-red-700"><X size={16} /></button>
                      </div>
                    ))}
                    <FraisDocument frais={frais} montant={v => montant(v, createForm.devise || null)} />
                    <div className="text-right font-medium dark:text-white">
                      Total HT: {montant(createLignes.reduce((s, l) => s + l.quantite * l.prix_unitaire_ht, 0) + frais.total, createForm.devise || null)}
                    </div>
                  </div>
                )}
              </div>
              <div className="flex justify-end gap-3 mt-6">
                <button type="button" onClick={fermerFormulaire}
                  className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-white">
                  Annuler
                </button>
                <button type="submit" disabled={verrou.bloque}
                  className="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:cursor-not-allowed disabled:opacity-50">
                  {editingId ? t.invoiceEdit.save : 'Créer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {aCrediter !== null && (
        <EditeurNoteCredit
          factureId={aCrediter}
          onClose={() => setACrediter(null)}
          onEnregistre={(avoir, emis) => {
            setACrediter(null);
            toast(emis ? t.creditNotes.issued.replace('{numero}', avoir.numero ?? '') : t.creditNotes.saved);
            loadFactures();
          }}
        />
      )}
      <Toasts toasts={toasts} onDismiss={dismiss} />
      {encaissement.dialogue}

      {/* Modal paiement */}
      {showPaiementModal && selectedFacture && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h2 className="text-xl font-bold mb-4 dark:text-white">Enregistrer un paiement</h2>
            <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
              Facture {selectedFacture.numero} - Reste à payer: {montant(Number(selectedFacture.reste_a_payer ?? 0), selectedFacture?.devise)}
            </p>
            {encaissement.caisse && <div className="mb-4"><CaisseDuPoste caisse={encaissement.caisse} /></div>}
            {acomptesClient.length > 0 && (
              <div className="mb-4 rounded-lg border border-indigo-200 bg-indigo-50/60 dark:border-indigo-800 dark:bg-indigo-900/20 p-3">
                <p className="text-sm font-medium text-indigo-900 dark:text-indigo-200 mb-1">{t.deposits.customerDeposits}</p>
                <ul className="space-y-1">
                  {acomptesClient.map(acompte => (
                    <li key={acompte.id} className="flex items-center justify-between gap-2 text-sm">
                      <span className="text-gray-700 dark:text-gray-300">
                        {formatDateDocument(acompte.date_acompte)} · {t.deposits.modes[acompte.mode] ?? acompte.mode}{acompte.reference && ` · ${acompte.reference}`}
                      </span>
                      <button type="button" onClick={() => { void appliquerDepuisPaiement(acompte); }}
                        className="px-2 py-1 rounded bg-indigo-600 text-white text-xs hover:bg-indigo-700 whitespace-nowrap">
                        {t.deposits.applyToInvoice.replace('{montant}', montant(Math.min(acompte.disponible, Number(selectedFacture.reste_a_payer)), selectedFacture.devise))}
                      </button>
                    </li>
                  ))}
                </ul>
              </div>
            )}
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
                  onChange={(e) => { setPaiementData({ ...paiementData, date_paiement: e.target.value }); proposerEscompte(selectedFacture, e.target.value); }}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                />
              </div>
              {escompte?.condition && (
                <label className={`flex items-start gap-2 text-sm rounded-lg p-2 ${escompte.applicable ? 'bg-green-50 text-green-900 dark:bg-green-900/20 dark:text-green-200' : 'bg-gray-50 text-gray-500 dark:bg-gray-700/40'}`}>
                  <input
                    type="checkbox"
                    className="mt-0.5"
                    disabled={!escompte.applicable}
                    checked={avecEscompte}
                    onChange={(e) => {
                      setAvecEscompte(e.target.checked);
                      setPaiementData({ ...paiementData, montant: String(e.target.checked ? escompte.a_payer : selectedFacture.reste_a_payer) });
                    }}
                  />
                  <span>
                    Escompte {escompte.condition} : {escompte.pourcentage} %, soit {montant(escompte.escompte, selectedFacture?.devise)} ; à payer {montant(escompte.a_payer, selectedFacture?.devise)}
                    <span className="block text-xs">
                      {escompte.applicable ? `Paiement au plus tard le ${formatDateDocument(escompte.date_limite)}, réglant tout le reste.` : `Délai dépassé (jusqu'au ${formatDateDocument(escompte.date_limite)}).`}
                    </span>
                  </span>
                </label>
              )}
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
                  <option value="carte_cadeau">{t.giftCards.giftCard}</option>
                </select>
              </div>
              {paiementData.mode_paiement === 'carte_cadeau' && (
                <ChampCarteCadeau numero={paiementData.carte_cadeau} onNumero={carte_cadeau => setPaiementData(d => ({ ...d, carte_cadeau }))}
                  onVerifiee={carte => setPaiementData(d => ({ ...d, montant: String(Math.min(Number(carte.solde), Number(selectedFacture.reste_a_payer ?? 0))) }))} />
              )}
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
