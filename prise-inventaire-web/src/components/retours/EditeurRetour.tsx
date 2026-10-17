import { Fragment, useEffect, useMemo, useState } from 'react';
import { Plus, Trash2, X } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { aujourdhui, dateSaisie, formatDateDocument } from '@/lib/documents';
import { quantiteAvecUnite } from '@/lib/unitesVente';
import { getClientsActifs, getProduits, getReferentiel, getSecteurs, type Client } from '@/services/api';
import {
  creerRetourClient, getPropositionRetour, modifierRetourClient, traiterRetourClient,
  type PropositionRetour, type RetourClient, type SaisieLigneRetour,
} from '@/services/retoursClients';
import type { Produit, Secteur } from '@/types';
import ExemplairesRetour from '@/components/lots/ExemplairesRetour';
import { numerosSaisis } from '@/lib/lots';
import { getExemplairesRetourClient, type ExemplairesProduit } from '@/services/lots';

/** Une ligne de l'écran : ce qui revient d'un produit, une part remise en stock (dans un secteur), une part au rebut. */
interface LigneEdition {
  cle: string;
  facture_ligne_id?: number;
  produit_id: string;
  enStock: string;
  secteur_id: string;
  rebut: string;
  raison_id: string;
  /** Produit suivi : le lot qui revient (vide : le dernier livré au client), numéros remis en stock et mis au rebut. */
  lot_id: string;
  series: string;
  seriesRebut: string;
}

const classeChamp = 'px-2 py-1.5 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const entier = (valeur: string) => Math.max(0, Math.floor(Number(valeur) || 0));
let compteur = 0;
const nouvelleCle = () => `l${++compteur}`;

/**
 * Éditeur d'un retour client, comme les lignes de retour de fripv2 : pour chaque produit, la quantité remise en stock
 * (et son secteur) et la quantité mise au rebut, avec la raison du retour. Retour d'une facture (ses lignes, au plus
 * ce qui reste à retourner) ou sans facture (produits choisis). Enregistre un brouillon, ou l'enregistre et le traite.
 */
export default function EditeurRetour({ factureId, brouillon, onClose, onEnregistre }: {
  /** Facture d'origine ; absente pour un retour sans facture. */
  factureId: number | null;
  /** Brouillon à modifier (avec ses lignes) ; absent pour un nouveau retour. */
  brouillon?: RetourClient | null;
  onClose: () => void;
  onEnregistre: (retour: RetourClient, traite: boolean) => void;
}) {
  const { t, language } = useLanguage();
  const tr = t.customerReturns;
  const { peut } = usePermissions();
  const [proposition, setProposition] = useState<PropositionRetour | null>(null);
  const [secteurs, setSecteurs] = useState<Secteur[]>([]);
  const [raisons, setRaisons] = useState<{ id: number; libelle: string }[]>([]);
  const [produits, setProduits] = useState<Produit[]>([]);
  const [clients, setClients] = useState<Client[]>([]);
  const [clientId, setClientId] = useState(brouillon ? String(brouillon.client_id) : '');
  const [lignes, setLignes] = useState<LigneEdition[]>([]);
  const [entete, setEntete] = useState({ date_retour: brouillon ? dateSaisie(brouillon.date_retour) : aujourdhui(), notes: brouillon?.notes ?? '' });
  // Brouillon créé par un premier essai (traitement refusé ensuite) : un nouvel essai le modifie au lieu d'en créer un autre.
  const [idBrouillon, setIdBrouillon] = useState<number | null>(brouillon?.id ?? null);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);
  const [charge, setCharge] = useState(false);

  useEffect(() => {
    let actif = true;
    const raisonsRetour = getReferentiel('raisons', true).then(liste => liste
      .filter(r => r.type === 'retour_client')
      .map(r => ({ id: r.id, libelle: String(r.libelle ?? r.code ?? r.id) })));
    Promise.all([
      getSecteurs('actifs'),
      raisonsRetour,
      factureId ? getPropositionRetour(factureId) : Promise.resolve(null),
      factureId ? Promise.resolve([] as Produit[]) : getProduits('actifs'),
      factureId || brouillon ? Promise.resolve([] as Client[]) : getClientsActifs(),
    ]).then(([listeSecteurs, listeRaisons, prop, listeProduits, listeClients]) => {
      if (!actif) return;
      setSecteurs(listeSecteurs);
      setRaisons(listeRaisons);
      setProduits(listeProduits);
      setClients(listeClients);
      setProposition(prop);
      // Brouillon : ses lignes regroupées par produit (part en stock, part au rebut) ; nouveau retour d'une facture :
      // une ligne par ligne facturée, sans quantité ; sans facture : une première ligne vide.
      const parCle = new Map<string, LigneEdition>();
      for (const l of brouillon?.lignes ?? []) {
        const cle = l.facture_ligne_id ? `f${l.facture_ligne_id}` : `p${l.produit_id}`;
        const ligne = parCle.get(cle) ?? { cle, facture_ligne_id: l.facture_ligne_id ?? undefined, produit_id: String(l.produit_id), enStock: '0', secteur_id: '', rebut: '0', raison_id: '', lot_id: '', series: '', seriesRebut: '' };
        if (l.traitement === 'remise_en_stock') {
          ligne.enStock = String(entier(ligne.enStock) + l.quantite);
          ligne.secteur_id = l.secteur_id ? String(l.secteur_id) : ligne.secteur_id;
          ligne.lot_id = l.lot_id ? String(l.lot_id) : ligne.lot_id;
          ligne.series = (l.numeros_serie ?? []).join('\n');
        } else {
          ligne.rebut = String(entier(ligne.rebut) + l.quantite);
          ligne.seriesRebut = (l.numeros_serie ?? []).join('\n');
        }
        ligne.raison_id = ligne.raison_id || (l.raison_id ? String(l.raison_id) : '');
        parCle.set(cle, ligne);
      }
      if (prop) {
        setLignes(prop.lignes.map(l => parCle.get(`f${l.facture_ligne_id}`) ?? {
          cle: `f${l.facture_ligne_id}`, facture_ligne_id: l.facture_ligne_id, produit_id: String(l.produit_id),
          enStock: '0', secteur_id: l.secteur_id ? String(l.secteur_id) : '', rebut: '0', raison_id: '', lot_id: '', series: '', seriesRebut: '',
        }));
      } else {
        const existantes = [...parCle.values()];
        setLignes(existantes.length > 0 ? existantes : [{ cle: nouvelleCle(), produit_id: '', enStock: '1', secteur_id: '', rebut: '0', raison_id: '', lot_id: '', series: '', seriesRebut: '' }]);
      }
      setCharge(true);
    }, e => { if (actif) setErreur(messageErreur(e, tr.error)); });
    return () => { actif = false; };
  }, [factureId, brouillon, tr.error]);

  const restes = useMemo(() => new Map((proposition?.lignes ?? []).map(l => [l.facture_ligne_id, l])), [proposition]);
  // Produits suivis : lots sortis chez le client et numéros qui lui ont été livrés.
  const [exemplaires, setExemplaires] = useState<Map<number, ExemplairesProduit>>(new Map());
  const clientDuRetour = proposition?.facture.client_id ?? brouillon?.client_id ?? (clientId ? Number(clientId) : null);
  const produitsDuRetour = [...new Set(lignes.map(l => Number(l.produit_id)).filter(id => id > 0))].sort((a, b) => a - b).join(',');
  useEffect(() => {
    if (!clientDuRetour || !produitsDuRetour) return;
    let actif = true;
    getExemplairesRetourClient(clientDuRetour, produitsDuRetour.split(',').map(Number))
      .then(liste => { if (actif) setExemplaires(new Map(liste.map(e => [e.produit_id, e]))); }, () => {});
    return () => { actif = false; };
  }, [clientDuRetour, produitsDuRetour]);
  const produitsParId = useMemo(() => new Map(produits.map(p => [String(p.id), p])), [produits]);
  const raisonExigee = raisons.length > 0;

  const calcul = lignes.map(ligne => {
    const enStock = entier(ligne.enStock);
    const rebut = entier(ligne.rebut);
    const facturee = ligne.facture_ligne_id ? restes.get(ligne.facture_ligne_id) : undefined;
    const depasse = facturee !== undefined && enStock + rebut > facturee.reste;
    const sansSecteur = enStock > 0 && !ligne.secteur_id;
    const sansRaison = raisonExigee && enStock + rebut > 0 && !ligne.raison_id;
    const sansProduit = !factureId && enStock + rebut > 0 && !ligne.produit_id;
    return { ligne, enStock, rebut, facturee, depasse, sansSecteur, sansRaison, sansProduit };
  });
  const retournees = calcul.filter(c => c.enStock + c.rebut > 0);
  const rienARetourner = proposition !== null && (!proposition.retournable || proposition.lignes.every(l => l.reste <= 0));
  const peutEnregistrer = idBrouillon ? peut('retours_clients', 'modifier') : peut('retours_clients', 'creer');
  const peutTraiter = peutEnregistrer && peut('retours_clients', 'traiter');
  const incomplet = calcul.some(c => c.depasse || c.sansSecteur || c.sansRaison || c.sansProduit) || (!factureId && !brouillon && !clientId);
  const bloque = occupe || !charge || rienARetourner || retournees.length === 0 || incomplet;

  const changer = (cle: string, champ: keyof LigneEdition, valeur: string) =>
    setLignes(ls => ls.map(l => {
      if (l.cle !== cle) return l;
      const modifiee = { ...l, [champ]: valeur };
      // Produit choisi (retour sans facture) : son secteur est proposé pour la remise en stock.
      if (champ === 'produit_id' && !l.secteur_id) {
        const secteur = produitsParId.get(valeur)?.secteur_id;
        if (secteur) modifiee.secteur_id = String(secteur);
      }
      return modifiee;
    }));
  const toutEnStock = () => setLignes(ls => ls.map(l => {
    const facturee = l.facture_ligne_id ? restes.get(l.facture_ligne_id) : undefined;
    return facturee ? { ...l, enStock: String(facturee.reste), rebut: '0' } : l;
  }));
  const toutEffacer = () => setLignes(ls => ls.map(l => ({ ...l, enStock: '0', rebut: '0' })));

  async function enregistrer(traiter: boolean) {
    if (retournees.length === 0) { setErreur(tr.noQuantity); return; }
    if (traiter && !confirm(tr.confirmProcess)) return;
    setOccupe(true);
    setErreur('');
    const saisieLignes: SaisieLigneRetour[] = retournees.flatMap(({ ligne, enStock, rebut }) => {
      const produit = ligne.facture_ligne_id ? { facture_ligne_id: ligne.facture_ligne_id } : { produit_id: Number(ligne.produit_id) };
      const raison = ligne.raison_id ? Number(ligne.raison_id) : null;
      return [
        ...(enStock > 0 ? [{ ...produit, quantite: enStock, traitement: 'remise_en_stock' as const, secteur_id: Number(ligne.secteur_id), raison_id: raison,
          lot_id: ligne.lot_id ? Number(ligne.lot_id) : null, numeros_serie: numerosSaisis(ligne.series) }] : []),
        ...(rebut > 0 ? [{ ...produit, quantite: rebut, traitement: 'rebut' as const, raison_id: raison, numeros_serie: numerosSaisis(ligne.seriesRebut) }] : []),
      ];
    });
    const saisie = { date_retour: entete.date_retour, notes: entete.notes.trim() || null, lignes: saisieLignes };
    try {
      let retour = idBrouillon
        ? await modifierRetourClient(idBrouillon, saisie)
        : await creerRetourClient(factureId ? { facture_id: factureId } : { client_id: Number(clientId) }, saisie);
      setIdBrouillon(retour.id);
      if (traiter) retour = await traiterRetourClient(retour.id);
      onEnregistre(retour, traiter);
    } catch (e) {
      setErreur(messageErreur(e, tr.error));
    } finally {
      setOccupe(false);
    }
  }

  const facture = proposition?.facture;
  const titre = brouillon
    ? tr.editDraft
    : facture ? tr.editorTitleInvoice.replace('{numero}', facture.numero) : factureId ? tr.loading : tr.editorTitleFree;
  const nomClient = facture?.client?.raison_sociale ?? brouillon?.client?.raison_sociale;
  const choixSecteur = (ligne: LigneEdition, enStock: number, sansSecteur: boolean, nom: string) => (
    <select value={ligne.secteur_id} onChange={e => changer(ligne.cle, 'secteur_id', e.target.value)} disabled={enStock === 0}
      aria-label={`${tr.sector} — ${nom}`} className={`${classeChamp} w-48 ${sansSecteur ? 'border-red-500' : ''}`}>
      <option value="">{tr.chooseSector}</option>
      {secteurs.map(s => <option key={s.id} value={s.id}>{s.code} — {s.nom}</option>)}
    </select>
  );
  const choixRaison = (ligne: LigneEdition, sansRaison: boolean, nom: string) => (
    <select value={ligne.raison_id} onChange={e => changer(ligne.cle, 'raison_id', e.target.value)}
      aria-label={`${tr.reason} — ${nom}`} className={`${classeChamp} w-44 ${sansRaison ? 'border-red-500' : ''}`}>
      <option value="">{raisonExigee ? tr.chooseReason : tr.noReason}</option>
      {raisons.map(r => <option key={r.id} value={r.id}>{r.libelle}</option>)}
    </select>
  );
  const champQuantite = (ligne: LigneEdition, champ: 'enStock' | 'rebut', libelle: string, rouge: boolean, nom: string, max?: number) => (
    <input type="number" min={0} max={max} step={1} value={ligne[champ]} aria-label={`${libelle} — ${nom}`}
      onChange={e => changer(ligne.cle, champ, e.target.value)}
      className={`${classeChamp} w-20 text-right ${rouge ? 'border-red-500 text-red-700' : ''}`} />
  );

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="editeur-retour-titre" className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-6xl max-h-[94vh] overflow-y-auto space-y-4">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 id="editeur-retour-titre" className="text-lg font-bold text-gray-900 dark:text-white">{titre}</h2>
            {(nomClient || facture) && (
              <p className="text-sm text-gray-500 dark:text-gray-400">
                {nomClient}{facture && ` · ${tr.invoice} ${facture.numero} · ${formatDateDocument(facture.date_facture, language)}`}
              </p>
            )}
          </div>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tr.close}><X size={18} /></button>
        </div>

        {!charge && !erreur && <p className="text-sm text-gray-500">{tr.loading}</p>}

        {charge && rienARetourner && (
          <p className="px-3 py-2 rounded-lg text-sm bg-amber-50 text-amber-800">{proposition?.retournable === false ? tr.notReturnable : tr.nothingLeft}</p>
        )}

        {charge && !rienARetourner && (
          <>
            <p className="text-sm text-gray-500 dark:text-gray-400">{tr.editorHelp}</p>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
              {!factureId && !brouillon && (
                <label className="text-xs text-gray-500 dark:text-gray-400">{tr.customer}
                  <select value={clientId} onChange={e => setClientId(e.target.value)} className={`${classeChamp} w-full ${clientId ? '' : 'border-amber-400'}`}>
                    <option value="">{tr.chooseCustomer}</option>
                    {clients.map(c => <option key={c.id} value={c.id}>{c.raison_sociale} ({c.code})</option>)}
                  </select>
                </label>
              )}
              <label className="text-xs text-gray-500 dark:text-gray-400">{tr.date}
                <input type="date" value={entete.date_retour} max={aujourdhui()} min={facture ? dateSaisie(facture.date_facture) : undefined}
                  onChange={e => setEntete({ ...entete, date_retour: e.target.value })} className={`${classeChamp} w-full`} />
              </label>
            </div>
            {!raisonExigee && <p className="text-xs text-gray-500 dark:text-gray-400">{tr.reasonsHelp}</p>}

            {factureId && (
              <div className="flex flex-wrap gap-2">
                <button type="button" onClick={toutEnStock} className="px-3 py-1.5 border rounded-lg text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{tr.returnAll}</button>
                <button type="button" onClick={toutEffacer} className="px-3 py-1.5 border rounded-lg text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{tr.clearAll}</button>
              </div>
            )}

            <div className="overflow-x-auto">
              <table className="w-full text-sm min-w-[56rem]">
                <thead>
                  <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300 border-b dark:border-gray-700">
                    <th className="py-2 pr-3 font-medium">{tr.product}</th>
                    {factureId && <th className="py-2 pr-3 font-medium text-right">{tr.remaining}</th>}
                    <th className="py-2 pr-3 font-medium text-right">{tr.restock}</th>
                    <th className="py-2 pr-3 font-medium">{tr.sector}</th>
                    <th className="py-2 pr-3 font-medium text-right">{tr.scrap}</th>
                    <th className="py-2 pr-3 font-medium">{tr.reason}</th>
                    {!factureId && <th className="py-2 font-medium" aria-label={tr.remove} />}
                  </tr>
                </thead>
                <tbody>
                  {calcul.map(({ ligne, enStock, rebut, facturee, depasse, sansSecteur, sansRaison, sansProduit }) => {
                    const produit = facturee?.produit ?? (ligne.produit_id ? produitsParId.get(ligne.produit_id) : undefined)
                      ?? brouillon?.lignes?.find(l => String(l.produit_id) === ligne.produit_id)?.produit;
                    const nom = produit?.description ?? (ligne.produit_id ? `#${ligne.produit_id}` : tr.product);
                    const epuisee = facturee !== undefined && facturee.reste <= 0;
                    const exemplaire = exemplaires.get(Number(ligne.produit_id));
                    // Quantités en unités de stock (une caisse de 12 rapportée : 12 numéros).
                    const facteur = facturee?.facteur ?? 1;
                    return (
                      <Fragment key={ligne.cle}>
                      <tr className={`border-b dark:border-gray-700 ${epuisee ? 'opacity-60' : ''}`}>
                        <td className="py-2 pr-3">
                          {factureId || brouillon ? (
                            <>
                              <span className="text-gray-900 dark:text-white">{nom}</span>
                              {produit?.numero && <span className="block text-xs font-mono text-gray-500">{produit.numero}</span>}
                            </>
                          ) : (
                            <select value={ligne.produit_id} onChange={e => changer(ligne.cle, 'produit_id', e.target.value)} aria-label={tr.product}
                              className={`${classeChamp} w-full min-w-[14rem] ${sansProduit ? 'border-red-500' : ''}`}>
                              <option value="">{tr.chooseProduct}</option>
                              {produits.map(p => <option key={p.id} value={p.id}>{p.numero} — {p.description}</option>)}
                            </select>
                          )}
                        </td>
                        {factureId && (
                          <td className="py-2 pr-3 text-right whitespace-nowrap text-gray-700 dark:text-gray-300">
                            {quantiteAvecUnite(facturee?.reste ?? 0, facturee?.unite)}
                            <span className="block text-xs text-gray-500">{tr.invoicedReturned.replace('{facturee}', String(facturee?.quantite_facturee ?? 0)).replace('{retournee}', String(facturee?.quantite_retournee ?? 0))}</span>
                          </td>
                        )}
                        <td className="py-2 pr-3 text-right">{champQuantite(ligne, 'enStock', tr.restock, depasse, nom, facturee?.reste)}</td>
                        <td className="py-2 pr-3">{choixSecteur(ligne, enStock, sansSecteur, nom)}</td>
                        <td className="py-2 pr-3 text-right">{champQuantite(ligne, 'rebut', tr.scrap, depasse, nom, facturee?.reste)}</td>
                        <td className="py-2 pr-3">{choixRaison(ligne, sansRaison, nom)}</td>
                        {!factureId && (
                          <td className="py-2 text-right">
                            <button type="button" onClick={() => setLignes(ls => ls.filter(l => l.cle !== ligne.cle))} disabled={lignes.length === 1}
                              title={tr.remove} aria-label={`${tr.remove} — ${nom}`} className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded disabled:opacity-40"><Trash2 size={16} /></button>
                          </td>
                        )}
                      </tr>
                      {exemplaire && enStock + rebut > 0 && (
                        <tr className="border-b dark:border-gray-700">
                          <td colSpan={7} className="pb-2 pr-3">
                            <ExemplairesRetour exemplaire={exemplaire} client lotId={ligne.lot_id} onLot={v => changer(ligne.cle, 'lot_id', v)}
                              series={ligne.series} onSeries={v => changer(ligne.cle, 'series', v)} attendus={Math.round(enStock * facteur)}
                              seriesRebut={ligne.seriesRebut} onSeriesRebut={v => changer(ligne.cle, 'seriesRebut', v)} attendusRebut={Math.round(rebut * facteur)} />
                          </td>
                        </tr>
                      )}
                      </Fragment>
                    );
                  })}
                </tbody>
              </table>
            </div>
            {!factureId && !brouillon?.facture_id && (
              <button type="button" onClick={() => setLignes(ls => [...ls, { cle: nouvelleCle(), produit_id: '', enStock: '1', secteur_id: '', rebut: '0', raison_id: '', lot_id: '', series: '', seriesRebut: '' }])}
                className="flex items-center gap-1 px-3 py-1.5 border rounded-lg text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                <Plus size={16} /> {tr.addProduct}
              </button>
            )}
            {calcul.some(c => c.depasse) && <p className="text-sm text-red-700">{tr.tooMuch}</p>}
            {calcul.some(c => c.sansSecteur) && <p className="text-sm text-red-700">{tr.missingSector}</p>}
            {calcul.some(c => c.sansRaison) && <p className="text-sm text-red-700">{tr.missingReason}</p>}

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
              className="px-4 py-2 border border-teal-300 text-teal-700 rounded-lg text-sm hover:bg-teal-50 disabled:opacity-50 dark:border-teal-500 dark:text-teal-300 dark:hover:bg-teal-900/30">{tr.saveDraft}</button>
          )}
          {peutTraiter && (
            <button type="button" onClick={() => { void enregistrer(true); }} disabled={bloque}
              className="px-4 py-2 bg-teal-600 text-white rounded-lg text-sm hover:bg-teal-700 disabled:opacity-50">{tr.saveAndProcess}</button>
          )}
        </div>
      </div>
    </div>
  );
}
