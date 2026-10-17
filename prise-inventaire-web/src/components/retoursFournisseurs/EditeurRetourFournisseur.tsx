import { Fragment, useEffect, useMemo, useState } from 'react';
import { Plus, Trash2, X } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { aujourdhui, dateSaisie, formatDateDocument, tauxPropose, useCodeDevise, useMontantDocument } from '@/lib/documents';
import ChampDevise, { type DeviseDocument } from '@/components/ChampDevise';
import { getFournisseursActifs, getProduits, getProduitsFournis, getReferentiel, getSecteurs, type Fournisseur } from '@/services/api';
import {
  creerRetourFournisseur, expedierRetourFournisseur, getPropositionCommande, getPropositionRetourClient, modifierRetourFournisseur,
  TYPES_RETOUR_FOURNISSEUR, type PropositionCommande, type PropositionRetourClient, type RetourFournisseur,
  type SaisieLigneRetourFournisseur, type TypeRetourFournisseur,
} from '@/services/retoursFournisseurs';
import type { Produit, Secteur } from '@/types';
import ExemplairesRetour from '@/components/lots/ExemplairesRetour';
import { numerosSaisis } from '@/lib/lots';
import { getExemplairesRetourFournisseur, type ExemplairesProduit } from '@/services/lots';

/** Origine d'un nouveau retour : une commande reçue, un retour client traité, ou aucune. */
export type OrigineRetourFournisseur = { commandeId: number } | { retourClientId: number } | null;

/** Une ligne de l'écran : produit renvoyé, quantité, prix crédité, secteur d'où il sort (vide : hors stock), raison. */
interface LigneEdition {
  cle: string;
  com_four_ligne_id?: number;
  retour_client_ligne_id?: number;
  produit_id: string;
  quantite: string;
  prix: string;
  secteur_id: string;
  raison_id: string;
  /** Produit suivi : le lot qui repart (vide : les premiers à périmer), les numéros de série renvoyés. */
  lot_id: string;
  series: string;
}

const classeChamp = 'px-2 py-1.5 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const entier = (valeur: string) => Math.max(0, Math.floor(Number(valeur) || 0));
let compteur = 0;
const nouvelleCle = () => `l${++compteur}`;

/**
 * Éditeur d'un retour fournisseur, comme les retours au fournisseur de fripv2 : pour chaque produit, la quantité qui
 * repart, le prix que le fournisseur crédite et le secteur d'où elle sort du stock (aucun pour une marchandise hors
 * stock, par exemple défectueuse et mise au rebut). Enregistre un brouillon, ou l'enregistre et l'expédie.
 */
export default function EditeurRetourFournisseur({ origine, brouillon, onClose, onEnregistre }: {
  origine: OrigineRetourFournisseur;
  /** Brouillon à modifier (avec ses lignes) ; absent pour un nouveau retour. */
  brouillon?: RetourFournisseur | null;
  onClose: () => void;
  onEnregistre: (retour: RetourFournisseur, expedie: boolean) => void;
}) {
  const { t, language } = useLanguage();
  const tr = t.supplierReturns;
  const { peut } = usePermissions();
  const montantDoc = useMontantDocument();
  const reference = useCodeDevise();
  const commandeId = brouillon ? brouillon.commande_fournisseur_id : origine && 'commandeId' in origine ? origine.commandeId : null;
  const retourClientId = brouillon ? brouillon.retour_client_id : origine && 'retourClientId' in origine ? origine.retourClientId : null;
  const [commande, setCommande] = useState<PropositionCommande | null>(null);
  const [retourClient, setRetourClient] = useState<PropositionRetourClient | null>(null);
  const [fournisseurs, setFournisseurs] = useState<Fournisseur[]>([]);
  const [produits, setProduits] = useState<Produit[]>([]);
  const [secteurs, setSecteurs] = useState<Secteur[]>([]);
  const [raisons, setRaisons] = useState<{ id: number; libelle: string }[]>([]);
  const [prixFournisseur, setPrixFournisseur] = useState<Map<number, number>>(new Map());
  const [fournisseurId, setFournisseurId] = useState(brouillon ? String(brouillon.fournisseur_id) : '');
  const [entete, setEntete] = useState({
    type: (brouillon?.type ?? 'remboursement') as TypeRetourFournisseur,
    date_retour: brouillon ? dateSaisie(brouillon.date_retour) : aujourdhui(),
    numero_rma: brouillon?.numero_rma ?? '',
    notes: brouillon?.notes ?? '',
  });
  const [devise, setDevise] = useState<DeviseDocument>({ devise: brouillon?.devise ?? '', taux: brouillon?.devise ? String(Number(brouillon.taux_change)) : '1' });
  const [lignes, setLignes] = useState<LigneEdition[]>([]);
  // Brouillon créé par un premier essai (expédition refusée ensuite) : un nouvel essai le modifie au lieu d'en créer un autre.
  const [idBrouillon, setIdBrouillon] = useState<number | null>(brouillon?.id ?? null);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);
  const [charge, setCharge] = useState(false);

  useEffect(() => {
    let actif = true;
    const raisonsRetour = getReferentiel('raisons', true).then(liste => liste
      .filter(r => r.type === 'retour_fournisseur')
      .map(r => ({ id: r.id, libelle: String(r.libelle ?? r.code ?? r.id) })));
    Promise.all([
      getSecteurs('actifs'),
      raisonsRetour,
      getFournisseursActifs(),
      commandeId ? getPropositionCommande(commandeId) : Promise.resolve(null),
      retourClientId ? getPropositionRetourClient(retourClientId) : Promise.resolve(null),
      commandeId || retourClientId ? Promise.resolve([] as Produit[]) : getProduits('actifs'),
    ]).then(([listeSecteurs, listeRaisons, listeFournisseurs, propCommande, propClient, listeProduits]) => {
      if (!actif) return;
      setSecteurs(listeSecteurs);
      setRaisons(listeRaisons);
      setFournisseurs(listeFournisseurs);
      setCommande(propCommande);
      setRetourClient(propClient);
      setProduits(listeProduits);
      // Brouillon : ses lignes, par ligne d'origine (ou par produit sans origine).
      const existantes = new Map<string, LigneEdition>();
      for (const l of brouillon?.lignes ?? []) {
        const cle = l.com_four_ligne_id ? `c${l.com_four_ligne_id}` : l.retour_client_ligne_id ? `r${l.retour_client_ligne_id}` : `p${l.id}`;
        existantes.set(cle, {
          cle, com_four_ligne_id: l.com_four_ligne_id ?? undefined, retour_client_ligne_id: l.retour_client_ligne_id ?? undefined,
          produit_id: String(l.produit_id), quantite: String(l.quantite), prix: String(Number(l.prix_unitaire)),
          secteur_id: l.secteur_id ? String(l.secteur_id) : '', raison_id: l.raison_id ? String(l.raison_id) : '',
          lot_id: l.lot_id ? String(l.lot_id) : '', series: (l.numeros_serie ?? []).join('\n'),
        });
      }
      if (propCommande) {
        setFournisseurId(String(propCommande.commande.fournisseur_id));
        setDevise({ devise: propCommande.devise.devise ?? '', taux: String(propCommande.devise.taux_change) });
        setLignes(propCommande.lignes.map(l => existantes.get(`c${l.com_four_ligne_id}`) ?? {
          cle: `c${l.com_four_ligne_id}`, com_four_ligne_id: l.com_four_ligne_id, produit_id: String(l.produit_id),
          quantite: '0', prix: String(l.prix_unitaire), secteur_id: l.secteur_id ? String(l.secteur_id) : '', raison_id: '', lot_id: '', series: '',
        }));
      } else if (propClient) {
        if (!brouillon && propClient.fournisseur_propose_id) setFournisseurId(String(propClient.fournisseur_propose_id));
        setLignes(propClient.lignes.map(l => existantes.get(`r${l.retour_client_ligne_id}`) ?? {
          cle: `r${l.retour_client_ligne_id}`, retour_client_ligne_id: l.retour_client_ligne_id, produit_id: String(l.produit_id),
          quantite: String(brouillon ? 0 : l.quantite_proposee), prix: '', secteur_id: l.secteur_id ? String(l.secteur_id) : '', raison_id: '', lot_id: '', series: '',
        }));
      } else {
        const libres = [...existantes.values()];
        setLignes(libres.length > 0 ? libres : [{ cle: nouvelleCle(), produit_id: '', quantite: '1', prix: '', secteur_id: '', raison_id: '', lot_id: '', series: '' }]);
      }
      setCharge(true);
    }, e => { if (actif) setErreur(messageErreur(e, tr.error)); });
    return () => { actif = false; };
  }, [commandeId, retourClientId, brouillon, tr.error]);

  // Fournisseur choisi (hors commande) : sa devise et ses derniers prix d'achat sont proposés.
  useEffect(() => {
    if (!charge || commandeId || !fournisseurId) return;
    let actif = true;
    getProduitsFournis(Number(fournisseurId)).then(catalogue => {
      if (actif) setPrixFournisseur(new Map(catalogue.map(p => [p.produit_id, Number(p.dernier_prix ?? p.prix_achat ?? 0)])));
    }, () => { if (actif) setPrixFournisseur(new Map()); });
    return () => { actif = false; };
  }, [charge, commandeId, fournisseurId]);

  useEffect(() => {
    if (prixFournisseur.size === 0) return;
    setLignes(ls => ls.map(l => (l.prix === '' && prixFournisseur.has(Number(l.produit_id)) ? { ...l, prix: String(prixFournisseur.get(Number(l.produit_id))) } : l)));
  }, [prixFournisseur]);

  const choisirFournisseur = (id: string) => {
    setFournisseurId(id);
    // La devise du fournisseur est proposée (celle de l'entreprise à défaut).
    const choisi = fournisseurs.find(f => String(f.id) === id);
    const code = String(choisi?.devise ?? '').toUpperCase();
    if (code && code !== reference) {
      setDevise({ devise: code, taux: '' });
      void tauxPropose(code, reference).then(taux => setDevise({ devise: code, taux }));
    } else {
      setDevise({ devise: '', taux: '1' });
    }
  };

  const restesCommande = useMemo(() => new Map((commande?.lignes ?? []).map(l => [l.com_four_ligne_id, l])), [commande]);
  const restesClient = useMemo(() => new Map((retourClient?.lignes ?? []).map(l => [l.retour_client_ligne_id, l])), [retourClient]);
  const produitsParId = useMemo(() => new Map(produits.map(p => [String(p.id), p])), [produits]);
  const raisonExigee = raisons.length > 0;
  const deviseDocument = devise.devise && devise.devise !== reference ? devise.devise : null;
  // Produits suivis : lots qui ont du stock (le premier à périmer d'abord) et numéros en stock.
  const [exemplaires, setExemplaires] = useState<Map<number, ExemplairesProduit>>(new Map());
  const produitsDuRetour = [...new Set(lignes.map(l => Number(l.produit_id)).filter(id => id > 0))].sort((a, b) => a - b).join(',');
  useEffect(() => {
    if (!produitsDuRetour) return;
    let actif = true;
    getExemplairesRetourFournisseur(produitsDuRetour.split(',').map(Number))
      .then(liste => { if (actif) setExemplaires(new Map(liste.map(e => [e.produit_id, e]))); }, () => {});
    return () => { actif = false; };
  }, [produitsDuRetour]);

  const calcul = lignes.map(ligne => {
    const quantite = entier(ligne.quantite);
    const prix = Math.max(0, Number(ligne.prix) || 0);
    const origine = ligne.com_four_ligne_id ? restesCommande.get(ligne.com_four_ligne_id) : ligne.retour_client_ligne_id ? restesClient.get(ligne.retour_client_ligne_id) : undefined;
    const reste = origine?.reste;
    const depasse = reste !== undefined && quantite > reste;
    const sansRaison = raisonExigee && quantite > 0 && !ligne.raison_id;
    const sansProduit = !commandeId && !retourClientId && quantite > 0 && !ligne.produit_id;
    return { ligne, quantite, prix, reste, origine, depasse, sansRaison, sansProduit, total: Math.round(quantite * prix * 100) / 100 };
  });
  const renvoyees = calcul.filter(c => c.quantite > 0);
  const total = renvoyees.reduce((somme, c) => somme + c.total, 0);
  const origineEpuisee = (commande !== null && (!commande.retournable || commande.lignes.every(l => l.reste <= 0)))
    || (retourClient !== null && (!retourClient.retournable || retourClient.lignes.every(l => l.reste <= 0)));
  const peutEnregistrer = idBrouillon ? peut('retours_fournisseurs', 'modifier') : peut('retours_fournisseurs', 'creer');
  const peutExpedier = peutEnregistrer && peut('retours_fournisseurs', 'expedier');
  const tauxManquant = !!deviseDocument && !(Number(devise.taux) > 0);
  const incomplet = calcul.some(c => c.depasse || c.sansRaison || c.sansProduit) || !fournisseurId || tauxManquant;
  const bloque = occupe || !charge || origineEpuisee || renvoyees.length === 0 || incomplet;

  const changer = (cle: string, champ: keyof LigneEdition, valeur: string) =>
    setLignes(ls => ls.map(l => {
      if (l.cle !== cle) return l;
      const modifiee = { ...l, [champ]: valeur };
      // Produit choisi (retour sans origine) : son dernier prix chez ce fournisseur et son secteur sont proposés.
      if (champ === 'produit_id') {
        if (prixFournisseur.has(Number(valeur))) modifiee.prix = String(prixFournisseur.get(Number(valeur)));
        const secteur = produitsParId.get(valeur)?.secteur_id;
        if (secteur && !l.secteur_id) modifiee.secteur_id = String(secteur);
      }
      return modifiee;
    }));

  async function enregistrer(expedier: boolean) {
    if (renvoyees.length === 0) { setErreur(tr.noQuantity); return; }
    if (expedier && !confirm(tr.confirmShip)) return;
    setOccupe(true);
    setErreur('');
    const saisieLignes: SaisieLigneRetourFournisseur[] = renvoyees.map(({ ligne, quantite, prix }) => ({
      ...(ligne.com_four_ligne_id ? { com_four_ligne_id: ligne.com_four_ligne_id } : ligne.retour_client_ligne_id ? { retour_client_ligne_id: ligne.retour_client_ligne_id } : { produit_id: Number(ligne.produit_id) }),
      quantite,
      prix_unitaire: prix,
      secteur_id: ligne.secteur_id ? Number(ligne.secteur_id) : null,
      raison_id: ligne.raison_id ? Number(ligne.raison_id) : null,
      lot_id: ligne.lot_id ? Number(ligne.lot_id) : null,
      numeros_serie: numerosSaisis(ligne.series),
    }));
    const saisie = {
      fournisseur_id: Number(fournisseurId),
      type: entete.type,
      date_retour: entete.date_retour,
      numero_rma: entete.numero_rma.trim() || null,
      notes: entete.notes.trim() || null,
      // La devise de l'entreprise s'envoie par son code (un champ vide reprendrait celle du fournisseur).
      devise: deviseDocument ?? reference,
      taux_change: deviseDocument ? Number(devise.taux) : 1,
      lignes: saisieLignes,
    };
    try {
      let retour = idBrouillon
        ? await modifierRetourFournisseur(idBrouillon, saisie)
        : await creerRetourFournisseur(commandeId ? { commande_fournisseur_id: commandeId } : retourClientId ? { retour_client_id: retourClientId } : {}, saisie);
      setIdBrouillon(retour.id);
      // Datée du jour de l'utilisateur (l'API compte en UTC).
      if (expedier) retour = await expedierRetourFournisseur(retour.id, aujourdhui());
      onEnregistre(retour, expedier);
    } catch (e) {
      setErreur(messageErreur(e, tr.error));
    } finally {
      setOccupe(false);
    }
  }

  const titre = brouillon
    ? tr.editDraft
    : commande ? tr.editorTitleOrder.replace('{numero}', commande.commande.numero)
      : retourClient ? tr.editorTitleCustomerReturn.replace('{numero}', retourClient.retour_client.numero ?? '')
        : commandeId || retourClientId ? tr.loading : tr.editorTitleFree;
  const sousTitre = commande
    ? `${commande.commande.fournisseur?.raison_sociale ?? ''} · ${formatDateDocument(commande.commande.date_commande, language)}`
    : retourClient ? `${tr.customer} : ${retourClient.retour_client.client?.raison_sociale ?? ''} · ${formatDateDocument(retourClient.retour_client.date_retour, language)}` : '';
  const sansOrigine = !commandeId && !retourClientId;

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="editeur-retour-fournisseur-titre" className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-6xl max-h-[94vh] overflow-y-auto space-y-4">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 id="editeur-retour-fournisseur-titre" className="text-lg font-bold text-gray-900 dark:text-white">{titre}</h2>
            {sousTitre && <p className="text-sm text-gray-500 dark:text-gray-400">{sousTitre}</p>}
          </div>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tr.close}><X size={18} /></button>
        </div>

        {!charge && !erreur && <p className="text-sm text-gray-500">{tr.loading}</p>}
        {charge && origineEpuisee && (
          <p className="px-3 py-2 rounded-lg text-sm bg-amber-50 text-amber-800">
            {(commande && !commande.retournable) || (retourClient && !retourClient.retournable) ? tr.notReturnable : tr.nothingLeft}
          </p>
        )}

        {charge && !origineEpuisee && (
          <>
            <p className="text-sm text-gray-500 dark:text-gray-400">{tr.editorHelp}</p>
            <div className="grid grid-cols-1 sm:grid-cols-4 gap-3">
              <label className="text-xs text-gray-500 dark:text-gray-400 sm:col-span-2">{tr.supplier}
                <select value={fournisseurId} onChange={e => choisirFournisseur(e.target.value)} disabled={!!commandeId}
                  className={`${classeChamp} w-full ${fournisseurId ? '' : 'border-amber-400'}`}>
                  <option value="">{tr.chooseSupplier}</option>
                  {fournisseurs.map(f => <option key={f.id} value={f.id}>{f.raison_sociale} ({f.code})</option>)}
                  {commande && !fournisseurs.some(f => f.id === commande.commande.fournisseur_id) && (
                    <option value={commande.commande.fournisseur_id}>{commande.commande.fournisseur?.raison_sociale}</option>
                  )}
                </select>
              </label>
              <label className="text-xs text-gray-500 dark:text-gray-400">{tr.type}
                <select value={entete.type} onChange={e => setEntete({ ...entete, type: e.target.value as TypeRetourFournisseur })} className={`${classeChamp} w-full`}>
                  {TYPES_RETOUR_FOURNISSEUR.map(type => <option key={type} value={type}>{tr.types[type]}</option>)}
                </select>
              </label>
              <label className="text-xs text-gray-500 dark:text-gray-400">{tr.date}
                <input type="date" value={entete.date_retour} max={aujourdhui()} onChange={e => setEntete({ ...entete, date_retour: e.target.value })} className={`${classeChamp} w-full`} />
              </label>
              <label className="text-xs text-gray-500 dark:text-gray-400 sm:col-span-2">{tr.rma}
                <input value={entete.numero_rma} maxLength={50} placeholder={tr.rmaHelp} onChange={e => setEntete({ ...entete, numero_rma: e.target.value })} className={`${classeChamp} w-full`} />
              </label>
              {commandeId ? (
                <p className="text-xs text-gray-500 dark:text-gray-400 sm:col-span-2 self-end">
                  {tr.currencyOfOrder.replace('{devise}', deviseDocument ?? reference)}
                </p>
              ) : (
                <ChampDevise valeur={devise} onChange={setDevise} className="sm:col-span-2" />
              )}
            </div>
            <p className="text-xs text-gray-500 dark:text-gray-400">{tr.typeHelp[entete.type]}</p>
            {!raisonExigee && <p className="text-xs text-gray-500 dark:text-gray-400">{tr.reasonsHelp}</p>}

            <div className="overflow-x-auto">
              <table className="w-full text-sm min-w-[60rem]">
                <thead>
                  <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300 border-b dark:border-gray-700">
                    <th className="py-2 pr-3 font-medium">{tr.product}</th>
                    {!sansOrigine && <th className="py-2 pr-3 font-medium text-right">{tr.remaining}</th>}
                    <th className="py-2 pr-3 font-medium text-right">{tr.quantity}</th>
                    <th className="py-2 pr-3 font-medium text-right">{tr.unitPrice}</th>
                    <th className="py-2 pr-3 font-medium">{tr.fromSector}</th>
                    <th className="py-2 pr-3 font-medium">{tr.reason}</th>
                    <th className="py-2 pr-3 font-medium text-right">{tr.lineTotal}</th>
                    {sansOrigine && <th className="py-2 font-medium" aria-label={tr.remove} />}
                  </tr>
                </thead>
                <tbody>
                  {calcul.map(({ ligne, quantite, reste, origine: o, depasse, sansRaison, sansProduit, total: totalLigne }) => {
                    const produit = o?.produit ?? (ligne.produit_id ? produitsParId.get(ligne.produit_id) : undefined)
                      ?? brouillon?.lignes?.find(l => String(l.produit_id) === ligne.produit_id)?.produit;
                    const nom = produit?.description ?? (ligne.produit_id ? `#${ligne.produit_id}` : tr.product);
                    const detailOrigine = o && 'quantite_recue' in o
                      ? tr.receivedReturned.replace('{recue}', String(o.quantite_recue)).replace('{retournee}', String(o.quantite_retournee))
                      : o && 'traitement' in o ? `${tr.fromCustomer.replace('{quantite}', String(o.quantite_retournee_client))} · ${tr.customerTreatments[o.traitement]}` : '';
                    const exemplaire = ligne.secteur_id ? exemplaires.get(Number(ligne.produit_id)) : undefined;
                    // En unités de stock : une ligne de commande se compte en unités d'achat.
                    const facteur = o && 'facteur' in o ? (o.facteur ?? 1) : 1;
                    return (
                      <Fragment key={ligne.cle}>
                      <tr className={`border-b dark:border-gray-700 ${reste === 0 ? 'opacity-60' : ''}`}>
                        <td className="py-2 pr-3">
                          {sansOrigine && !brouillon ? (
                            <select value={ligne.produit_id} onChange={e => changer(ligne.cle, 'produit_id', e.target.value)} aria-label={tr.product}
                              className={`${classeChamp} w-full min-w-[14rem] ${sansProduit ? 'border-red-500' : ''}`}>
                              <option value="">{tr.chooseProduct}</option>
                              {produits.map(p => <option key={p.id} value={p.id}>{p.numero} — {p.description}</option>)}
                            </select>
                          ) : (
                            <>
                              <span className="text-gray-900 dark:text-white">{nom}</span>
                              {produit?.numero && <span className="block text-xs font-mono text-gray-500">{produit.numero}</span>}
                            </>
                          )}
                        </td>
                        {!sansOrigine && (
                          <td className="py-2 pr-3 text-right whitespace-nowrap text-gray-700 dark:text-gray-300">
                            {reste ?? 0}
                            {detailOrigine && <span className="block text-xs text-gray-500">{detailOrigine}</span>}
                          </td>
                        )}
                        <td className="py-2 pr-3 text-right">
                          <input type="number" min={0} max={reste} step={1} value={ligne.quantite} aria-label={`${tr.quantity} — ${nom}`}
                            onChange={e => changer(ligne.cle, 'quantite', e.target.value)}
                            className={`${classeChamp} w-20 text-right ${depasse ? 'border-red-500 text-red-700' : ''}`} />
                        </td>
                        <td className="py-2 pr-3 text-right">
                          <input type="number" min={0} step="0.01" value={ligne.prix} aria-label={`${tr.unitPrice} — ${nom}`}
                            onChange={e => changer(ligne.cle, 'prix', e.target.value)} className={`${classeChamp} w-28 text-right`} />
                        </td>
                        <td className="py-2 pr-3">
                          <select value={ligne.secteur_id} onChange={e => changer(ligne.cle, 'secteur_id', e.target.value)}
                            aria-label={`${tr.fromSector} — ${nom}`} className={`${classeChamp} w-48`}>
                            <option value="">{tr.notInStock}</option>
                            {secteurs.map(s => <option key={s.id} value={s.id}>{s.code} — {s.nom}</option>)}
                          </select>
                        </td>
                        <td className="py-2 pr-3">
                          <select value={ligne.raison_id} onChange={e => changer(ligne.cle, 'raison_id', e.target.value)}
                            aria-label={`${tr.reason} — ${nom}`} className={`${classeChamp} w-44 ${sansRaison ? 'border-red-500' : ''}`}>
                            <option value="">{raisonExigee ? tr.chooseReason : tr.noReason}</option>
                            {raisons.map(r => <option key={r.id} value={r.id}>{r.libelle}</option>)}
                          </select>
                        </td>
                        <td className="py-2 pr-3 text-right whitespace-nowrap font-medium text-gray-900 dark:text-white">{montantDoc(totalLigne, deviseDocument)}</td>
                        {sansOrigine && (
                          <td className="py-2 text-right">
                            <button type="button" onClick={() => setLignes(ls => ls.filter(l => l.cle !== ligne.cle))} disabled={lignes.length === 1}
                              title={tr.remove} aria-label={`${tr.remove} — ${nom}`} className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded disabled:opacity-40"><Trash2 size={16} /></button>
                          </td>
                        )}
                      </tr>
                      {/* Produit suivi qui sort du stock : le lot, ou les numéros de série renvoyés. */}
                      {exemplaire && quantite > 0 && (
                        <tr className="border-b dark:border-gray-700">
                          <td colSpan={8} className="pb-2 pr-3">
                            <ExemplairesRetour exemplaire={exemplaire} client={false} lotId={ligne.lot_id} onLot={v => changer(ligne.cle, 'lot_id', v)}
                              series={ligne.series} onSeries={v => changer(ligne.cle, 'series', v)} attendus={Math.round(quantite * facteur)} />
                          </td>
                        </tr>
                      )}
                      </Fragment>
                    );
                  })}
                </tbody>
              </table>
            </div>
            <div className="flex flex-wrap items-center justify-between gap-2">
              {sansOrigine && !brouillon ? (
                <button type="button" onClick={() => setLignes(ls => [...ls, { cle: nouvelleCle(), produit_id: '', quantite: '1', prix: '', secteur_id: '', raison_id: '', lot_id: '', series: '' }])}
                  className="flex items-center gap-1 px-3 py-1.5 border rounded-lg text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                  <Plus size={16} /> {tr.addProduct}
                </button>
              ) : <span />}
              <p className="text-sm"><span className="text-gray-500">{entete.type === 'remboursement' ? tr.expectedCredit : tr.value}</span>{' '}
                <span className="font-bold text-gray-900 dark:text-white">{montantDoc(total, deviseDocument)}</span></p>
            </div>
            {calcul.some(c => c.depasse) && <p className="text-sm text-red-700">{tr.tooMuch}</p>}
            {calcul.some(c => c.sansRaison) && <p className="text-sm text-red-700">{tr.missingReason}</p>}
            {tauxManquant && <p className="text-sm text-red-700">{tr.missingRate}</p>}

            <label className="block text-xs text-gray-500 dark:text-gray-400">{tr.notes}
              <textarea value={entete.notes} rows={2} maxLength={2000} onChange={e => setEntete({ ...entete, notes: e.target.value })} className={`${classeChamp} w-full`} />
            </label>
          </>
        )}

        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

        <div className="flex flex-wrap justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tr.cancel}</button>
          {peutEnregistrer && (
            <button type="button" onClick={() => { void enregistrer(false); }} disabled={bloque}
              className="px-4 py-2 border border-orange-300 text-orange-700 rounded-lg text-sm hover:bg-orange-50 disabled:opacity-50 dark:border-orange-500 dark:text-orange-300 dark:hover:bg-orange-900/30">{tr.saveDraft}</button>
          )}
          {peutExpedier && (
            <button type="button" onClick={() => { void enregistrer(true); }} disabled={bloque}
              className="px-4 py-2 bg-orange-600 text-white rounded-lg text-sm hover:bg-orange-700 disabled:opacity-50">{tr.saveAndShip}</button>
          )}
        </div>
      </div>
    </div>
  );
}
