import { usePermissions } from '@/contexts/PermissionsContext';
import BoutonPiecesJointes from '@/components/BoutonPiecesJointes';
import { useState, useEffect, useCallback } from 'react';
import { useRechercheAdresse } from '@/hooks/useRechercheAdresse';
import BoutonChampsPersonnalises from '@/components/BoutonChampsPersonnalises';
import { Plus, Eye, Check, X, FileText, Send, Trash2, Pencil, Wallet, Hourglass, Search } from 'lucide-react';
import ReliquatsDepuisCommande from '@/components/reliquats/ReliquatsDepuisCommande';
import type { ManqueCommande } from '@/services/disponibilites';
import FormulaireAcompte from '@/components/acomptes/FormulaireAcompte';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';
import { useLanguage } from '@/i18n/useLanguage';
import { useVerrou } from '@/hooks/useVerrou';
import BandeauVerrou from '@/components/BandeauVerrou';
import ChoixRepresentant from '@/components/ChoixRepresentant';
import { useRepresentants } from '@/hooks/useRepresentants';
import ChoixTransporteur from '@/components/ChoixTransporteur';
import { ChoixStatutPersonnalise, FiltreStatutPersonnalise, PastilleStatutPersonnalise } from '@/components/StatutPersonnalise';
import { lienSuivi, transporteursActifs, useTransporteurRequis, useTransporteurs } from '@/hooks/useTransporteurs';
import BalisesFiche from '@/components/BalisesFiche';
import BoutonRemarquesRapides from '@/components/BoutonRemarquesRapides';
import { ajouterRemarque } from '@/lib/utils';
import {
  getCommandesClient,
  getClientsActifs,
  createCommandeClient,
  updateCommandeClient,
  soumettreCommandeClient,
  accepterCommandeClient,
  refuserCommandeClient,
  creerFactureDepuisCommande,
  deleteCommandeClient,
  getCommandeClient,
} from '../services/api';
import type { ComClientEntete, Client } from '../services/api';
import { getProduits } from '../services/api';
import type { Produit } from '../types';
import RecapitulatifTaxes from '@/components/RecapitulatifTaxes';
import BoutonPdf from '@/components/BoutonPdf';
import BoutonCourriel from '@/components/BoutonCourriel';
import Pagination from '@/components/Pagination';
import { signaler } from '@/lib/erreurs';
import { aujourdhui, dateSaisie, formatDateDocument, tauxPropose, useCodeDevise, useMontantDocument } from '@/lib/documents';
import ChampDevise from '@/components/ChampDevise';
import { useFraisAutomatiques } from '@/hooks/useFraisAutomatiques';
import FraisDocument from '@/components/FraisDocument';
import { usePrixAutomatiques } from '@/hooks/usePrixAutomatiques';
import { useReferencesClient } from '@/hooks/useReferencesClient';
import OriginePrix from '@/components/OriginePrix';
import { appliquerPrix, changerUnite, choisirProduit, dansLaDevise, saisirPrix, type LignePrix } from '@/lib/prixDocuments';
import { annexesALaVente, prixUnite, uniteParDefaut } from '@/lib/unitesVente';
import ChoixUnite from '@/components/ChoixUnite';
import ChampsClientDePassage, { BoutonClientDePassage, DetailClientDePassage, PastilleClientDePassage } from '@/components/ClientDePassage';
import { PASSAGE_VIDE, avecClient, nomClient, saisiePassage } from '@/lib/clientDePassage';

export default function CommandesClientPage() {
  // Boutons d'action selon les permissions (rôles et permissions).
  const { peut, canCreate } = usePermissions();
  const { t } = useLanguage();
  const { toasts, toast, dismiss } = useToast();
  // Commande pour laquelle saisir un acompte (réservé à elle, appliqué à sa facture à l'émission).
  const [acomptePour, setAcomptePour] = useState<ComClientEntete | null>(null);
  // Montants dans la devise de chaque document (celle de l'entreprise, ou celle d'un client étranger).
  const montantDoc = useMontantDocument();
  const montant = (v: number | string | null | undefined, deviseDoc?: string | null) => montantDoc(v, deviseDoc);
  const deviseEntreprise = useCodeDevise();
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState({ dernierePage: 1, total: 0 });
  const [commandes, setCommandes] = useState<ComClientEntete[]>([]);
  const [clients, setClients] = useState<Client[]>([]);
  const [produits, setProduits] = useState<Produit[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterStatut, setFilterStatut] = useState('');
  // Représentants commerciaux (phase 1.11a) : filtre de la liste, et celui de la commande (par défaut, celui du client).
  const representants = useRepresentants();
  const [filterRepresentant, setFilterRepresentant] = useState<number | null>(null);
  const [representantId, setRepresentantId] = useState<number | null>(null);
  // Transporteur (phase 1.11b) : celui du client par défaut ; champs masqués sans transporteur actif.
  const transporteurs = useTransporteurs();
  const transporteurRequis = useTransporteurRequis(transporteurs);
  const [filterTransporteur, setFilterTransporteur] = useState<number | null>(null);
  // Statut personnalisé (étiquette de repérage, phase 1.11c).
  const [filterStatutPerso, setFilterStatutPerso] = useState<number | null>(null);
  const [transporteurId, setTransporteurId] = useState<number | null>(null);
  const [numeroSuivi, setNumeroSuivi] = useState('');
  // Recherche par numéro ou client, pré-remplie par « ?recherche= » (recherche globale, reliquats, autres écrans).
  const [search, setSearch] = useRechercheAdresse();
  const [recherche, setRecherche] = useState(() => search.trim());
  const [showModal, setShowModal] = useState(false);
  // Commande acceptée dont des produits manquent : reliquats à noter.
  const [reliquatsDe, setReliquatsDe] = useState<number | null>(null);
  // Manques signalés à l'acceptation, proposés comme reliquats (par produit).
  const [manquesProposes, setManquesProposes] = useState<Record<number, number> | undefined>(undefined);
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [selectedCommande, setSelectedCommande] = useState<ComClientEntete | null>(null);
  const [showRefusModal, setShowRefusModal] = useState(false);
  const [motifRefus, setMotifRefus] = useState('');
  const [editingId, setEditingId] = useState<number | null>(null);
  // Commande verrouillée pendant sa modification : un autre compte voit qui la modifie.
  const verrou = useVerrou('commande_client', showModal ? editingId : null);

  const [formData, setFormData] = useState({
    client_id: '',
    date_commande: aujourdhui(),
    date_livraison_souhaitee: '',
    remise_globale: '0',
    notes: '',
    // Devise de la commande (vide : celle de l'entreprise) et son taux ; par défaut, celle du client.
    devise: '',
    taux: '1',
  });
  // Coordonnées du client de passage, notées sur la commande.
  const [passage, setPassage] = useState(PASSAGE_VIDE);
  const clientDePassage = clients.find(c => c.id === Number(formData.client_id))?.de_passage;
  // Référence du produit chez le client choisi, à côté de sa désignation (phase 1.2c).
  const referencesClient = useReferencesClient(formData.client_id ? Number(formData.client_id) : null);
  const avecReference = (produitId?: number) => {
    const reference = produitId ? referencesClient.get(produitId) : undefined;
    return reference ? ` — ${t.clientFile.references.short.replace('{ref}', reference)}` : '';
  };
  const [lignes, setLignes] = useState<(LignePrix & { prix_unitaire_ht: number })[]>([]);
  // Frais additionnels et de livraison proposés pour la commande en cours de saisie (ceux déjà sur un brouillon
  // modifié ne sont pas proposés de nouveau).
  const frais = useFraisAutomatiques({
    document: 'commande',
    clientId: formData.client_id ? Number(formData.client_id) : null,
    transporteurId,
    lignes,
    taux: formData.devise ? Number(formData.taux) || 1 : 1,
    actif: showModal,
  });
  // Prix du client (liste, remise, règles) proposés sur les lignes dont le prix n'a pas été saisi.
  usePrixAutomatiques({
    clientId: formData.client_id ? Number(formData.client_id) : null,
    date: formData.date_commande,
    taux: formData.devise ? Number(formData.taux) || 1 : 1,
    lignes,
    actif: showModal,
    appliquer: prix => setLignes(ls => appliquerPrix(ls, prix, 'prix_unitaire_ht', p => dansLaDevise(p, formData.devise, Number(formData.taux)), t.pricing.simulator.origins)),
  });

  // La recherche part 300 ms après la dernière frappe, et non à chaque caractère.
  useEffect(() => {
    const minuterie = setTimeout(() => { setRecherche(search.trim()); setPage(1); }, 300);
    return () => clearTimeout(minuterie);
  }, [search]);

  const loadData = useCallback(async () => {
    try {
      setLoading(true);
      const [commandesRes, clientsRes, produitsRes] = await Promise.all([
        getCommandesClient({ statut: filterStatut || undefined, recherche: recherche || undefined, representant_id: filterRepresentant ?? undefined, transporteur_id: filterTransporteur ?? undefined, statut_personnalise_id: filterStatutPerso ?? undefined, page }),
        getClientsActifs(),
        getProduits(),
      ]);
      setCommandes(commandesRes.data || []);
      setPagination({ dernierePage: commandesRes.last_page ?? 1, total: commandesRes.total ?? 0 });
      setClients(clientsRes || []);
      setProduits(produitsRes || []);
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }, [filterStatut, recherche, filterRepresentant, filterTransporteur, filterStatutPerso, page]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (lignes.length === 0) {
      alert('Ajoutez au moins une ligne');
      return;
    }
    const payload = {
      client_id: parseInt(formData.client_id),
      date_commande: formData.date_commande,
      date_livraison_souhaitee: formData.date_livraison_souhaitee || undefined,
      remise_globale: parseFloat(formData.remise_globale) || 0,
      notes: formData.notes || undefined,
      // À la création : toujours envoyée (la devise de l'entreprise l'emporte alors sur celle du client).
      devise: formData.devise || deviseEntreprise,
      taux_change: formData.devise ? Number(formData.taux) : undefined,
      lignes: [
        ...lignes.map(l => ({ produit_id: l.produit_id, quantite: l.quantite, prix_unitaire_ht: l.prix_unitaire_ht, unite: l.unite ?? null })),
        ...frais.lignes.map(l => ({ produit_id: l.produit_id, quantite: l.quantite, prix_unitaire_ht: l.prix_unitaire_ht })),
      ],
      // Retenues par l'API pour le client de passage seulement.
      ...passage,
      representant_id: representantId,
      transporteur_id: transporteurId,
      numero_suivi: numeroSuivi.trim() || null,
    };
    try {
      if (editingId) {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        await updateCommandeClient(editingId, payload as any);
      } else {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        await createCommandeClient(payload as any);
      }
      setShowModal(false);
      resetForm();
      loadData();
    } catch (error) {
      signaler(error, 'Erreur lors de l\'enregistrement de la commande');
    }
  };

  const handleOpenEdit = (cmd: ComClientEntete) => {
    setEditingId(cmd.id);
    setFormData({
      client_id: String(cmd.client_id),
      date_commande: dateSaisie(cmd.date_commande),
      date_livraison_souhaitee: dateSaisie(cmd.date_livraison_souhaitee),
      remise_globale: String(cmd.remise_globale ?? '0'),
      notes: cmd.notes || '',
      devise: cmd.devise ?? '',
      taux: String(cmd.taux_change ?? 1),
    });
    setPassage(saisiePassage(cmd));
    setRepresentantId(cmd.representant_id ?? null);
    setTransporteurId(cmd.transporteur_id ?? null);
    setNumeroSuivi(cmd.numero_suivi ?? '');
    setLignes(
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (cmd.lignes ?? []).map((l: any) => ({
        produit_id: l.produit_id,
        quantite: l.quantite,
        prix_unitaire_ht: parseFloat(l.prix_unitaire_ht),
        unite: l.unite ?? null,
      }))
    );
    setShowModal(true);
  };

  const handleSoumettre = async (id: number) => {
    try {
      await soumettreCommandeClient(id);
      loadData();
    } catch (error) {
      signaler(error, 'Erreur lors de la soumission');
    }
  };

  const handleAccepter = async (id: number) => {
    try {
      const acceptee = await accepterCommandeClient(id) as ComClientEntete & { manques?: ManqueCommande[] };
      loadData();
      // Stock insuffisant : l'acceptation est faite ; on propose de noter les quantités qui manquent en reliquat.
      const manques = acceptee.manques ?? [];
      if (manques.length > 0 && peut('reliquats_clients', 'creer')
        && confirm(t.availability.shortageOnAccept.replace('{produits}', manques.map(m => `${m.description} (${m.manque})`).join(', ')))) {
        setManquesProposes(Object.fromEntries(manques.map(m => [m.produit_id, m.manque])));
        setReliquatsDe(id);
      }
    } catch (error) {
      signaler(error, 'Erreur lors de l\'acceptation');
      loadData();
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
      signaler(error, 'Erreur lors du refus');
    }
  };

  const handleCreerFacture = async (id: number) => {
    try {
      const facture = await creerFactureDepuisCommande(id, aujourdhui());
      alert(`Facture ${facture.numero} créée (brouillon).`);
      loadData();
    } catch (error) {
      signaler(error, 'Erreur lors de la création de la facture');
    }
  };

  const handleDelete = async (id: number) => {
    if (!confirm('Supprimer cette commande ?')) return;
    try {
      await deleteCommandeClient(id);
      loadData();
    } catch (error) {
      signaler(error, 'Impossible de supprimer cette commande.');
    }
  };

  const addLigne = () => {
    setLignes([...lignes, { produit_id: 0, quantite: 1, prix_unitaire_ht: 0, auto: true }]);
  };

  const updateLigne = (index: number, field: 'produit_id' | 'quantite' | 'prix_unitaire_ht', value: number) => {
    setLignes(ls => {
      const maj = ls.map((ligne, i) => {
        if (i !== index) return ligne;
        if (field === 'prix_unitaire_ht') return saisirPrix(ligne, 'prix_unitaire_ht', value);
        if (field === 'quantite') return { ...ligne, quantite: value };
        // Prix du produit proposé tant qu'aucun prix n'a été saisi (converti pour une commande en devise étrangère),
        // remplacé ensuite par celui du client ; dans l'unité de vente proposée d'office pour ce produit.
        const produit = produits.find(p => p.id === value);
        const unite = uniteParDefaut(produit);
        return { ...choisirProduit(ligne, value, 'prix_unitaire_ht', dansLaDevise(prixUnite(produit, unite), formData.devise, Number(formData.taux))), unite };
      });
      if (field !== 'produit_id') return maj;
      // Annexes du produit choisi (la pile fournie avec l'appareil), ajoutées d'office après sa ligne.
      const produit = produits.find(p => p.id === value);
      const annexes = annexesALaVente(produit, ls[index]?.quantite ?? 1, uniteParDefaut(produit), produits).map(a => ({
        produit_id: Number(a.produit.id), quantite: a.quantite, unite: null,
        prix_unitaire_ht: dansLaDevise(prixUnite(a.produit, null), formData.devise, Number(formData.taux)), auto: true,
      }));
      return [...maj.slice(0, index + 1), ...annexes, ...maj.slice(index + 1)];
    });
  };

  // Unité de la ligne : le prix de cette unité est proposé, sauf prix saisi.
  const changerUniteLigne = (index: number, unite: string | null) => setLignes(ls => ls.map((ligne, i) => (i !== index ? ligne
    : changerUnite(ligne, unite, 'prix_unitaire_ht', dansLaDevise(prixUnite(produits.find(p => p.id === ligne.produit_id), unite), formData.devise, Number(formData.taux))))));

  const removeLigne = (index: number) => {
    setLignes(lignes.filter((_, i) => i !== index));
  };

  const resetForm = () => {
    setEditingId(null);
    frais.reinitialiser();
    setFormData({
      client_id: '',
      date_commande: aujourdhui(),
      date_livraison_souhaitee: '',
      remise_globale: '0',
      notes: '',
      devise: '',
      taux: '1',
    });
    setPassage(PASSAGE_VIDE);
    setRepresentantId(null);
    setTransporteurId(null);
    setNumeroSuivi('');
    setLignes([]);
  };

  // Client choisi : sa devise est proposée, avec son taux ; son représentant aussi.
  const choisirClient = (id: string, liste: Client[] = clients) => {
    const deviseClient = liste.find(c => c.id === Number(id))?.devise ?? '';
    setRepresentantId(liste.find(c => c.id === Number(id))?.representant_id ?? null);
    setTransporteurId(liste.find(c => c.id === Number(id))?.transporteur_id ?? null);
    setFormData(f => ({ ...f, client_id: id, devise: deviseClient, taux: deviseClient ? '' : '1' }));
    if (deviseClient) tauxPropose(deviseClient, deviseEntreprise).then(taux => setFormData(f => (f.devise === deviseClient ? { ...f, taux } : f)));
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
        <div className="flex flex-wrap gap-4">
          <div className="relative flex-1 min-w-[200px]">
            <Search size={20} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <input
              type="text"
              placeholder="Rechercher par numéro ou client..."
              aria-label="Rechercher une commande"
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
            <option value="en_attente">En attente</option>
            <option value="acceptee">Acceptée</option>
            <option value="refusee">Refusée</option>
            <option value="facturee">Facturée</option>
          </select>
          <ChoixRepresentant value={filterRepresentant} onChange={id => { setFilterRepresentant(id); setPage(1); }} representants={representants}
            libelleVide={t.salesReps.all} ariaLabel={t.salesReps.label} className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
          {transporteursActifs(transporteurs) && (
            <ChoixTransporteur value={filterTransporteur} onChange={id => { setFilterTransporteur(id); setPage(1); }} transporteurs={transporteurs}
              libelleVide={t.carriers.all} ariaLabel={t.carriers.label} className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
          )}
          <FiltreStatutPersonnalise document="commande_client" value={filterStatutPerso} onChange={id => { setFilterStatutPerso(id); setPage(1); }}
            className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
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
                  <td className="px-4 py-3 font-mono text-sm text-gray-900 dark:text-white">
                    {cmd.numero}
                    <BalisesFiche entite="commande_client" entiteId={cmd.id} />
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{nomClient(cmd)}<PastilleClientDePassage client={cmd.client} /></td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{formatDateDocument(cmd.date_commande)}</td>
                  <td className="px-4 py-3 text-right text-sm font-medium text-gray-900 dark:text-white">
                    {montant(Number(cmd.montant_ttc ?? 0), cmd.devise)}
                  </td>
                  <td className="px-4 py-3 text-center">
                    {getStatutBadge(cmd.statut)}
                    {cmd.statut_personnalise && <span className="block mt-1"><PastilleStatutPersonnalise statut={cmd.statut_personnalise} /></span>}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex justify-center gap-1">
                      <BoutonPiecesJointes entite="commande_client" entiteId={cmd.id} titre={cmd.numero} />
                      <button
                        onClick={() => {
                          setSelectedCommande(cmd);
                          setShowDetailModal(true);
                          // Fiche complète (détail des taxes), si la commande affichée n'a pas changé entre-temps.
                          getCommandeClient(cmd.id)
                            .then(complete => setSelectedCommande(actuelle => (actuelle?.id === complete.id ? complete : actuelle)))
                            .catch(() => {});
                        }}
                        className="p-1 text-gray-600 hover:bg-gray-100 rounded"
                        title="Voir détails"
                      >
                        <Eye size={18} />
                      </button>
                      <BoutonPdf chemin={`/commandes-client/${cmd.id}/pdf`} nomFichier={`commande-${cmd.numero}.pdf`} />
                      <BoutonCourriel type="commande_client" documentId={cmd.id} />
                      {['brouillon', 'en_attente', 'acceptee'].includes(cmd.statut) && (
                        <>{peut('acomptes', 'creer') && (
                          <button
                            onClick={() => setAcomptePour(cmd)}
                            className="p-1 text-indigo-600 hover:bg-indigo-50 rounded"
                            title={t.deposits.newDeposit}
                            aria-label={`${t.deposits.newDeposit} — ${cmd.numero}`}
                          >
                            <Wallet size={18} />
                          </button>
                        )}</>
                      )}
                      {cmd.statut === 'brouillon' && (
                        <>
                          <button
                            onClick={() => handleOpenEdit(cmd)}
                            className="p-1 text-blue-600 hover:bg-blue-50 rounded"
                            title="Modifier"
                          >
                            <Pencil size={18} />
                          </button>
                          {peut('commandes_client', 'soumettre') && (
                            <button
                              onClick={() => handleSoumettre(cmd.id)}
                              className="p-1 text-indigo-600 hover:bg-indigo-50 rounded"
                              title="Soumettre"
                            >
                              <Send size={18} />
                            </button>
                          )}
                          <button
                            onClick={() => handleDelete(cmd.id)}
                            className="p-1 text-red-600 hover:bg-red-50 rounded"
                            title="Supprimer"
                          >
                            <Trash2 size={18} />
                          </button>
                        </>
                      )}
                      {cmd.statut === 'en_attente' && (
                        <>
                          {peut('commandes_client', 'decider') && (
                            <button
                              onClick={() => handleAccepter(cmd.id)}
                              className="p-1 text-green-600 hover:bg-green-50 rounded"
                              title="Accepter"
                            >
                              <Check size={18} />
                            </button>
                          )}
                          {peut('commandes_client', 'decider') && (
                            <button
                              onClick={() => { setSelectedCommande(cmd); setShowRefusModal(true); }}
                              className="p-1 text-red-600 hover:bg-red-50 rounded"
                              title="Refuser"
                            >
                              <X size={18} />
                            </button>
                          )}
                        </>
                      )}
                      {cmd.statut === 'acceptee' && (
                        <>{canCreate('factures') && (
                          <button
                            onClick={() => handleCreerFacture(cmd.id)}
                            className="p-1 text-purple-600 hover:bg-purple-50 rounded"
                            title="Créer facture"
                          >
                            <FileText size={18} />
                          </button>
                        )}</>
                      )}
                      {['acceptee', 'facturee'].includes(cmd.statut) && peut('reliquats_clients', 'creer') && (
                        <button
                          onClick={() => setReliquatsDe(cmd.id)}
                          className="p-1 text-sky-600 hover:bg-sky-50 rounded"
                          title={t.backOrders.fromOrderAction}
                          aria-label={`${t.backOrders.fromOrderAction} — ${cmd.numero}`}
                        >
                          <Hourglass size={18} />
                        </button>
                      )}
                      <BoutonChampsPersonnalises entite="commande_client" entiteId={cmd.id} titre={cmd.numero} />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <Pagination page={page} dernierePage={pagination.dernierePage} total={pagination.total} onPage={setPage} />
      </div>

      {/* Modal création */}
      {showModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-3xl max-h-[90vh] overflow-y-auto">
            <h2 className="text-xl font-bold mb-4 dark:text-white">{editingId ? 'Modifier la commande' : 'Nouvelle commande'}</h2>
            <BandeauVerrou verrou={verrou} />
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Client *</label>
                  <select
                    required
                    value={formData.client_id}
                    onChange={(e) => choisirClient(e.target.value)}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  >
                    <option value="">Sélectionner...</option>
                    {clients.map(c => (
                      <option key={c.id} value={c.id}>{c.raison_sociale}</option>
                    ))}
                  </select>
                  {!clientDePassage && <BoutonClientDePassage onChoisir={c => { setClients(cs => avecClient(cs, c)); choisirClient(String(c.id), [c]); }} />}
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
              {clientDePassage && <ChampsClientDePassage valeur={passage} onChange={setPassage} />}
              {editingId ? (formData.devise && <p className="text-sm text-gray-500 dark:text-gray-400">{t.currency.label} : {formData.devise} (1 {formData.devise} = {formData.taux} {deviseEntreprise})</p>) : (
                <ChampDevise valeur={{ devise: formData.devise, taux: formData.taux }} onChange={v => setFormData(f => ({ ...f, devise: v.devise, taux: v.taux }))} />
              )}

              <div>
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">Notes</label>
                <textarea
                  value={formData.notes}
                  onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  rows={2}
                />
                <BoutonRemarquesRapides onInserer={texte => setFormData(f => ({ ...f, notes: ajouterRemarque(f.notes, texte) }))} />
              </div>

              <div>
                <label htmlFor="commande-representant" className="block text-sm font-medium mb-1 dark:text-gray-300">{t.salesReps.label}</label>
                <ChoixRepresentant id="commande-representant" value={representantId} onChange={setRepresentantId} representants={representants} />
                <p className="text-xs text-gray-500 mt-1">{t.salesReps.documentHint}</p>
              </div>
              {(transporteursActifs(transporteurs) || transporteurId !== null) && (
                <div>
                  <label htmlFor="commande-transporteur" className="block text-sm font-medium mb-1 dark:text-gray-300">{t.carriers.label}{transporteurRequis ? ' *' : ''}</label>
                  <ChoixTransporteur id="commande-transporteur" value={transporteurId} onChange={setTransporteurId} transporteurs={transporteurs} requis={transporteurRequis} />
                  <p className="text-xs text-gray-500 mt-1">{t.carriers.documentHint}</p>
                </div>
              )}
              {(transporteursActifs(transporteurs) || transporteurId !== null || numeroSuivi !== '') && (
                <div>
                  <label htmlFor="commande-suivi" className="block text-sm font-medium mb-1 dark:text-gray-300">{t.carriers.tracking}</label>
                  <input id="commande-suivi" type="text" maxLength={60} value={numeroSuivi} onChange={e => setNumeroSuivi(e.target.value)}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                  <p className="text-xs text-gray-500 mt-1">{t.carriers.trackingHint}</p>
                </div>
              )}

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
                            <option key={p.id} value={p.id}>{p.description}{avecReference(p.id)}</option>
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
                        <ChoixUnite produit={produits.find(p => p.id === ligne.produit_id)} valeur={ligne.unite} onChange={u => changerUniteLigne(idx, u)}
                          libelle={t.productUnits.lineUnit.replace('{n}', String(idx + 1))} />
                        <input
                          type="number"
                          step="0.01"
                          value={ligne.prix_unitaire_ht}
                          onChange={(e) => updateLigne(idx, 'prix_unitaire_ht', parseFloat(e.target.value) || 0)}
                          className="w-24 px-2 py-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                          placeholder="Prix HT"
                        />
                        <span className="text-sm text-gray-600 dark:text-gray-400 w-20 text-right">
                          {montant((ligne.quantite * ligne.prix_unitaire_ht), formData.devise || null)}
                        </span>
                        <OriginePrix info={ligne.info_prix} />
                        <button type="button" onClick={() => removeLigne(idx)} className="text-red-500 hover:text-red-700">
                          <X size={16} />
                        </button>
                      </div>
                    ))}
                    <FraisDocument frais={frais} montant={v => montant(v, formData.devise || null)} />
                    <div className="text-right font-medium text-gray-900 dark:text-white">
                      Total HT: {montant(totalLignes + frais.total, formData.devise || null)}
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
                <button type="submit" disabled={verrou.bloque}
                  className="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:cursor-not-allowed disabled:opacity-50">
                  {editingId ? 'Enregistrer' : 'Créer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal détail */}
      {showDetailModal && selectedCommande && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-2xl">
            <h2 className="text-xl font-bold mb-4 dark:text-white">
              Commande {selectedCommande.numero}
            </h2>
            <div className="space-y-3">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <span className="text-sm text-gray-500">Client:</span>
                  <p className="font-medium dark:text-white">{nomClient(selectedCommande)}<PastilleClientDePassage client={selectedCommande.client} /></p>
                  <DetailClientDePassage document={selectedCommande} />
                </div>
                <div>
                  <span className="text-sm text-gray-500">Statut:</span>
                  <p>{getStatutBadge(selectedCommande.statut)}</p>
                </div>
                <ChoixStatutPersonnalise document="commande_client" documentId={selectedCommande.id} statut={selectedCommande.statut_personnalise} modifiable={peut('commandes_client', 'modifier')}
                  onChange={statut => { setSelectedCommande(d => (d ? { ...d, statut_personnalise: statut, statut_personnalise_id: statut?.id ?? null } : d)); loadData(); }} />
                <div>
                  <span className="text-sm text-gray-500">Date commande:</span>
                  <p className="dark:text-white">{formatDateDocument(selectedCommande.date_commande)}</p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Date livraison souhaitée:</span>
                  <p className="dark:text-white">{formatDateDocument(selectedCommande.date_livraison_souhaitee)}</p>
                </div>
                {selectedCommande.transporteur && (
                  <div>
                    <span className="text-sm text-gray-500">{t.carriers.label} :</span>
                    <p className="dark:text-white">
                      {selectedCommande.transporteur.nom}
                      {selectedCommande.numero_suivi && <span className="block text-xs text-gray-500">{t.carriers.tracking} : {selectedCommande.numero_suivi}</span>}
                      {lienSuivi(selectedCommande.transporteur.url_suivi, selectedCommande.numero_suivi) && (
                        <a href={lienSuivi(selectedCommande.transporteur.url_suivi, selectedCommande.numero_suivi) ?? undefined} target="_blank" rel="noopener noreferrer"
                          className="text-xs text-blue-600 hover:underline whitespace-nowrap">{t.carriers.track}</a>
                      )}
                    </p>
                  </div>
                )}
                {selectedCommande.representant && (
                  <div>
                    <span className="text-sm text-gray-500">{t.salesReps.label} :</span>
                    <p className="dark:text-white">{selectedCommande.representant.nom}</p>
                  </div>
                )}
              </div>
              <div className="border-t pt-3 dark:border-gray-700">
                <div className="flex justify-between">
                  <span className="text-gray-500">Montant HT:</span>
                  <span className="dark:text-white">{montant(Number(selectedCommande.montant_ht ?? 0), selectedCommande.devise)}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">TVA:</span>
                  <span className="dark:text-white">{montant(Number(selectedCommande.montant_tva ?? 0), selectedCommande.devise)}</span>
                </div>
                <RecapitulatifTaxes recapitulatif={selectedCommande.recapitulatif_taxes} devise={selectedCommande.devise} />
                <div className="flex justify-between font-bold">
                  <span className="dark:text-white">Total TTC:</span>
                  <span className="dark:text-white">{montant(Number(selectedCommande.montant_ttc ?? 0), selectedCommande.devise)}</span>
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
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h2 className="text-xl font-bold mb-4 dark:text-white">Refuser la commande</h2>
            <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
              Commande {selectedCommande.numero} - {nomClient(selectedCommande)}
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
      {acomptePour && (
        <FormulaireAcompte
          clientId={acomptePour.client_id}
          commandeId={acomptePour.id}
          onClose={() => setAcomptePour(null)}
          onEnregistre={() => { setAcomptePour(null); toast(t.deposits.saved); }}
        />
      )}
      {reliquatsDe !== null && (
        <ReliquatsDepuisCommande
          commandeId={reliquatsDe}
          proposees={manquesProposes}
          onClose={() => { setReliquatsDe(null); setManquesProposes(undefined); }}
          onEnregistre={nombre => { setReliquatsDe(null); setManquesProposes(undefined); toast(t.backOrders.fromOrderSaved.replace('{n}', String(nombre))); }}
        />
      )}
      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}
