import { Fragment, useEffect, useMemo, useState } from 'react';
import { CheckCircle, Plus, SplitSquareHorizontal, X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { aujourdhui, useCodeDevise, useMontantDocument } from '@/lib/documents';
import { fraisPourApi, type FraisSaisi } from '@/lib/coutsAnnexes';
import { messageErreur } from '@/lib/erreurs';
import { numerosSaisis } from '@/lib/lots';
import { getFournisseursActifs, getLignesEnAttente, type ComFourEntete, type ComFourLigne, type Fournisseur } from '@/services/api';
import { enregistrerReception, type ResultatReception, type SaisieReception } from '@/services/receptions';
import CoutsAnnexes from '@/components/receptions/CoutsAnnexes';
import OptionsSecteurs from '@/components/OptionsSecteurs';
import type { Secteur } from '@/types';

/** Ligne de commande attendue, avec sa commande (numéro, devise). */
interface LigneAttendue {
  ligne: ComFourLigne & { id: number };
  commande: ComFourEntete;
  restant: number;
}

/** Rangée saisie : la première d'une ligne de commande porte le coût et la clôture ; les suivantes sont des répartitions. */
interface Rangee {
  cle: string;
  ligneId: number;
  quantite: number;
  secteur_id: number | null;
  lot: string;
  peremption: string;
  /** Numéros de série saisis (produit suivi par numéro de série), un par ligne. */
  series: string;
  note: string;
}

const classeChamp = 'w-full px-2 py-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';
const classeEntete = 'px-2 py-2 text-left text-xs font-medium text-gray-600 dark:text-gray-300';

let compteur = 0;
const nouvelleCle = () => `r${++compteur}`;


function attendues(commande: ComFourEntete, lignes: ComFourLigne[]): LigneAttendue[] {
  return lignes.filter((l): l is ComFourLigne & { id: number } => l.id !== undefined)
    .map(ligne => ({ ligne, commande, restant: Math.max(0, ligne.quantite_commandee - ligne.quantite_recue) }));
}

/**
 * Réception d'une livraison : les lignes en attente d'une commande (et d'autres commandes du même fournisseur si la
 * livraison les couvre), avec le bon de livraison et le connaissement ; par ligne, la quantité reçue (répartie au
 * besoin sur plusieurs secteurs), le coût facturé, le lot, la péremption, et la clôture d'une ligne dont le reste ne
 * viendra pas. Recevoir plus que le reste demande une confirmation. Les coûts annexes (transport, douane…) se
 * répartissent sur les lignes.
 */
export default function FenetreReception({ commande, lignes: lignesInitiales, secteurs, commandesEnAttente, onClose, onEnregistree }: {
  commande: ComFourEntete;
  lignes: ComFourLigne[];
  secteurs: Secteur[];
  /** Commandes en attente de livraison : celles du même fournisseur peuvent s'ajouter à la réception. */
  commandesEnAttente: ComFourEntete[];
  onClose: () => void;
  onEnregistree: (resultat: ResultatReception) => void;
}) {
  const { t } = useLanguage();
  const tr = t.receipts;
  const montant = useMontantDocument();
  const codeEntreprise = useCodeDevise();
  const [lignes, setLignes] = useState<LigneAttendue[]>(() => attendues(commande, lignesInitiales));
  const [rangees, setRangees] = useState<Rangee[]>(() => attendues(commande, lignesInitiales).map(a => ({
    cle: nouvelleCle(), ligneId: a.ligne.id, quantite: a.restant, secteur_id: null, lot: '', peremption: '', series: '', note: '',
  })));
  const [couts, setCouts] = useState<Record<number, string>>(() => Object.fromEntries(lignesInitiales.map(l => [l.id, String(Number(l.prix_unitaire))])));
  const [clotures, setClotures] = useState<number[]>([]);
  const [date, setDate] = useState(aujourdhui());
  const [bonLivraison, setBonLivraison] = useState('');
  const [connaissement, setConnaissement] = useState('');
  const [notes, setNotes] = useState('');
  const [erreur, setErreur] = useState('');
  const [enCours, setEnCours] = useState(false);
  const [frais, setFrais] = useState<FraisSaisi[]>([]);
  const [fournisseurs, setFournisseurs] = useState<Fournisseur[]>([]);

  useEffect(() => {
    let actif = true;
    getFournisseursActifs().then(f => { if (actif) setFournisseurs(f ?? []); }, () => {});
    return () => { actif = false; };
  }, []);

  const parLigne = useMemo(() => new Map(lignes.map(a => [a.ligne.id, a])), [lignes]);
  // Devises des coûts annexes : celle de l'entreprise, puis celles des commandes reçues (avec leur taux).
  const tauxParDevise = useMemo(() => Object.fromEntries(lignes.filter(a => a.commande.devise && a.commande.devise !== codeEntreprise)
    .map(a => [a.commande.devise as string, Number(a.commande.taux_change) || 0])), [lignes, codeEntreprise]);
  const devises = Object.keys(tauxParDevise);
  const incluses = new Set(lignes.map(a => a.commande.id));
  const autresCommandes = commandesEnAttente.filter(c => c.fournisseur_id === commande.fournisseur_id && !incluses.has(c.id));
  const recuPar = (ligneId: number) => rangees.filter(r => r.ligneId === ligneId).reduce((s, r) => s + (r.quantite || 0), 0);

  const changer = (cle: string, champs: Partial<Rangee>) => setRangees(rs => rs.map(r => (r.cle === cle ? { ...r, ...champs } : r)));

  function toutRecevoir() {
    setRangees(rs => rs.map((r, index) => {
      const premiere = rs.findIndex(x => x.ligneId === r.ligneId) === index;
      if (!premiere) return r;
      const ailleurs = rs.filter((x, i) => x.ligneId === r.ligneId && i !== index).reduce((s, x) => s + (x.quantite || 0), 0);
      return { ...r, quantite: Math.max(0, (parLigne.get(r.ligneId)?.restant ?? 0) - ailleurs) };
    }));
  }

  function repartir(ligneId: number) {
    setRangees(rs => {
      const derniere = rs.map(r => r.ligneId).lastIndexOf(ligneId);
      const copie = [...rs];
      copie.splice(derniere + 1, 0, { cle: nouvelleCle(), ligneId, quantite: 0, secteur_id: null, lot: '', peremption: '', series: '', note: '' });
      return copie;
    });
  }

  async function ajouterCommande(id: number) {
    const ajoutee = commandesEnAttente.find(c => c.id === id);
    if (!ajoutee) return;
    try {
      const nouvelles = attendues(ajoutee, await getLignesEnAttente(id) ?? []);
      setLignes(ls => [...ls, ...nouvelles]);
      setRangees(rs => [...rs, ...nouvelles.map(a => ({
        cle: nouvelleCle(), ligneId: a.ligne.id, quantite: a.restant, secteur_id: null, lot: '', peremption: '', series: '', note: '',
      }))]);
      setCouts(cs => ({ ...cs, ...Object.fromEntries(nouvelles.map(a => [a.ligne.id, String(Number(a.ligne.prix_unitaire))])) }));
    } catch (e) {
      setErreur(messageErreur(e, tr.error));
    }
  }

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    setErreur('');
    const receptions: SaisieReception[] = rangees.filter(r => r.quantite > 0).map(r => ({
      com_four_ligne_id: r.ligneId,
      quantite_recue: r.quantite,
      cout_unitaire: couts[r.ligneId] === '' || couts[r.ligneId] === undefined ? null : Number(couts[r.ligneId]),
      secteur_id: r.secteur_id ?? undefined,
      lot_numero: r.lot || undefined,
      date_peremption: r.peremption || undefined,
      numeros_serie: parLigne.get(r.ligneId)?.ligne.produit?.suivi === 'serie' ? numerosSaisis(r.series) : undefined,
      notes: r.note || undefined,
    }));
    if (receptions.length === 0 && clotures.length === 0) {
      setErreur(tr.nothingToSave);
      return;
    }
    // Produits suivis (lot, péremption, un numéro de série par unité) : vérifiés avant l'envoi.
    const manques = rangees.filter(r => r.quantite > 0).flatMap(r => {
      const attendue = parLigne.get(r.ligneId);
      const produit = attendue?.ligne.produit;
      const nom = produit?.numero ?? produit?.description ?? '';
      if (!attendue || !produit?.suivi) return [];
      if (produit.suivi === 'lot') {
        if (!r.lot.trim()) return [tr.lotRequired.replace('{produit}', nom)];
        return produit.peremption && !r.peremption ? [tr.expiryRequired.replace('{produit}', nom)] : [];
      }
      const attendus = r.quantite * (attendue.ligne.qte_par_unite_achat ?? 1);
      const saisis = numerosSaisis(r.series).length;
      return saisis === attendus ? [] : [tr.serialsMismatch.replace('{produit}', nom).replace('{n}', String(saisis)).replace('{attendus}', String(attendus))];
    });
    if (manques.length > 0) {
      setErreur(manques.join('\n'));
      return;
    }
    // Sur-réception : plus que le reste commandé sur une ligne, à confirmer.
    const surplus = lignes.filter(a => recuPar(a.ligne.id) > a.restant)
      .map(a => `• ${a.ligne.produit?.description ?? a.ligne.produit_id} : ${recuPar(a.ligne.id)} / ${a.restant}`);
    if (surplus.length > 0 && !confirm(tr.confirmOverReceipt.replace('{lignes}', surplus.join('\n')))) return;

    setEnCours(true);
    try {
      onEnregistree(await enregistrerReception({
        date_reception: date,
        bon_livraison: bonLivraison || undefined,
        connaissement: connaissement || undefined,
        notes: notes || undefined,
        accepter_surplus: surplus.length > 0,
        receptions,
        clotures,
        frais: fraisPourApi(frais, codeEntreprise),
      }));
    } catch (erreurApi) {
      setErreur(messageErreur(erreurApi, tr.error));
    } finally {
      setEnCours(false);
    }
  }

  // Quantité en unités d'achat et son équivalent en unités de stock (« 6 PACK = 144 UN »), si elles diffèrent.
  const unites = (a: LigneAttendue, quantite: number) => {
    const parUnite = a.ligne.qte_par_unite_achat ?? 1;
    return a.ligne.unite_achat && parUnite > 1 ? `${quantite} ${a.ligne.unite_achat} = ${quantite * parUnite} ${a.ligne.produit?.mesure ?? 'UN'}` : '';
  };

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <div role="dialog" aria-modal="true" aria-label={`${tr.title} — ${commande.numero}`}
        className="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-6xl max-h-[94vh] overflow-y-auto">
        <div className="flex items-center justify-between p-4 border-b dark:border-gray-700 sticky top-0 bg-white dark:bg-gray-800 z-10">
          <div>
            <h2 className="text-lg font-semibold dark:text-white">{tr.title} — {[...incluses].map(id => lignes.find(a => a.commande.id === id)?.commande.numero).join(', ')}</h2>
            <p className="text-sm text-gray-500">{commande.fournisseur?.raison_sociale}</p>
          </div>
          <button type="button" onClick={onClose} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" aria-label={tr.close}><X size={20} /></button>
        </div>

        <form onSubmit={enregistrer} className="p-4 space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <label className="block text-sm">
              <span className="block text-gray-700 dark:text-gray-300 mb-1">{tr.receiveDate} *</span>
              <input type="date" value={date} onChange={e => setDate(e.target.value)} required className={classeChamp} />
            </label>
            <label className="block text-sm">
              <span className="block text-gray-700 dark:text-gray-300 mb-1">{tr.deliveryNote}</span>
              <input value={bonLivraison} onChange={e => setBonLivraison(e.target.value)} maxLength={60} placeholder={tr.optional} className={classeChamp} />
            </label>
            <label className="block text-sm">
              <span className="block text-gray-700 dark:text-gray-300 mb-1">{tr.billOfLading}</span>
              <input value={connaissement} onChange={e => setConnaissement(e.target.value)} maxLength={60} placeholder={tr.optional} className={classeChamp} />
            </label>
            <label className="block text-sm">
              <span className="block text-gray-700 dark:text-gray-300 mb-1">{tr.notes}</span>
              <input value={notes} onChange={e => setNotes(e.target.value)} maxLength={2000} placeholder={tr.optional} className={classeChamp} />
            </label>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <button type="button" onClick={toutRecevoir} className="flex items-center gap-1 px-3 py-1.5 text-sm rounded-lg border border-green-600 text-green-700 hover:bg-green-50 dark:text-green-300 dark:hover:bg-green-900/30">
              <CheckCircle size={14} /> {tr.receiveAll}
            </button>
            <button type="button" onClick={() => setRangees(rs => rs.map(r => ({ ...r, quantite: 0 })))} className="px-3 py-1.5 text-sm rounded-lg border dark:border-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">
              {tr.clearAll}
            </button>
            {autresCommandes.length > 0 && (
              <select aria-label={tr.addOrder} value="" onChange={e => { if (e.target.value) ajouterCommande(Number(e.target.value)); }}
                className="px-2 py-1.5 text-sm border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                <option value="">{tr.chooseOrder}</option>
                {autresCommandes.map(c => <option key={c.id} value={c.id}>{c.numero}</option>)}
              </select>
            )}
          </div>

          <div className="border dark:border-gray-700 rounded-lg overflow-x-auto">
            <table className="w-full min-w-[960px]">
              <thead className="bg-gray-50 dark:bg-gray-700/50">
                <tr>
                  <th className={classeEntete}>{tr.product}</th>
                  <th className={`${classeEntete} w-28`}>{tr.received}</th>
                  <th className={`${classeEntete} w-36`}>{tr.unitCost}</th>
                  <th className={`${classeEntete} w-40`}>{tr.sector}</th>
                  <th className={`${classeEntete} w-28`}>{tr.lot}</th>
                  <th className={`${classeEntete} w-36`}>{tr.expiry}</th>
                  <th className={`${classeEntete} w-36`}>{tr.lineNote}</th>
                  <th className={`${classeEntete} w-20 text-center`} title={tr.closeHelp}>{tr.closeLine}</th>
                  <th className="w-10" />
                </tr>
              </thead>
              <tbody className="divide-y dark:divide-gray-700">
                {rangees.map((r, index) => {
                  const attendue = parLigne.get(r.ligneId);
                  if (!attendue) return null;
                  const premiere = rangees.findIndex(x => x.ligneId === r.ligneId) === index;
                  const total = recuPar(r.ligneId);
                  const devise = attendue.commande.devise;
                  const suivi = attendue.ligne.produit?.suivi;
                  const attendus = r.quantite * (attendue.ligne.qte_par_unite_achat ?? 1);
                  return (
                    <Fragment key={r.cle}>
                    <tr className={premiere ? '' : 'bg-gray-50/60 dark:bg-gray-900/20'}>
                      <td className="px-2 py-2 text-sm dark:text-gray-200">
                        {premiere ? (
                          <>
                            <div className="font-medium">{attendue.ligne.produit?.description ?? `#${attendue.ligne.produit_id}`}</div>
                            <div className="text-xs text-gray-500">
                              {attendue.commande.numero} · {tr.remaining.replace('{n}', unites(attendue, attendue.restant) || String(attendue.restant))}
                            </div>
                          </>
                        ) : (
                          <span className="pl-4 text-xs text-gray-500">↳ {tr.splitRow}</span>
                        )}
                      </td>
                      <td className="px-2 py-2">
                        <input type="number" min={0} value={r.quantite} aria-label={`${tr.received} ${attendue.ligne.produit?.description ?? ''}`}
                          onChange={e => changer(r.cle, { quantite: Math.max(0, Math.floor(Number(e.target.value) || 0)) })}
                          className={`${classeChamp} text-center ${total > attendue.restant ? 'bg-red-50 border-red-300 dark:bg-red-900/30' : ''}`} />
                        {premiere && total > 0 && unites(attendue, total) && <p className="text-[11px] text-orange-600 text-center mt-0.5">{unites(attendue, total)}</p>}
                      </td>
                      <td className="px-2 py-2">
                        {premiere && (
                          <>
                            <div className="flex items-center gap-1">
                              <input type="number" min={0} step="0.0001" value={couts[r.ligneId] ?? ''} aria-label={`${tr.unitCost} ${attendue.ligne.produit?.description ?? ''}`}
                                onChange={e => setCouts(cs => ({ ...cs, [r.ligneId]: e.target.value }))} className={classeChamp} />
                              {devise && <span className="text-xs text-gray-500">{devise}</span>}
                            </div>
                            <p className="text-[11px] text-gray-500 mt-0.5">{tr.orderedAt.replace('{prix}', montant(attendue.ligne.prix_unitaire, devise))}</p>
                          </>
                        )}
                      </td>
                      <td className="px-2 py-2">
                        <select value={r.secteur_id ?? ''} aria-label={tr.sector} onChange={e => changer(r.cle, { secteur_id: e.target.value ? Number(e.target.value) : null })} className={classeChamp}>
                          <option value="">{tr.defaultSector}</option>
                          {/* Multi-entrepôts : les secteurs de l'entrepôt où la commande est livrée d'abord. */}
                          <OptionsSecteurs secteurs={secteurs} prefere={attendue.commande.entrepot_id ?? commande.entrepot_id} />
                        </select>
                      </td>
                      <td className="px-2 py-2">
                        <input value={r.lot} aria-label={tr.lot} maxLength={50} onChange={e => changer(r.cle, { lot: e.target.value })}
                          required={suivi === 'lot' && r.quantite > 0} className={`${classeChamp} ${suivi === 'lot' && !r.lot.trim() && r.quantite > 0 ? 'border-amber-400' : ''}`} />
                      </td>
                      <td className="px-2 py-2">
                        <input type="date" value={r.peremption} aria-label={tr.expiry} onChange={e => changer(r.cle, { peremption: e.target.value })}
                          required={suivi === 'lot' && !!attendue.ligne.produit?.peremption && r.quantite > 0} className={classeChamp} />
                      </td>
                      <td className="px-2 py-2">
                        <input value={r.note} aria-label={tr.lineNote} onChange={e => changer(r.cle, { note: e.target.value })} className={classeChamp} />
                      </td>
                      <td className="px-2 py-2 text-center">
                        {premiere && (
                          <input type="checkbox" className="rounded" title={tr.closeHelp} aria-label={`${tr.closeLine} ${attendue.ligne.produit?.description ?? ''}`}
                            checked={clotures.includes(r.ligneId)}
                            onChange={e => setClotures(cs => (e.target.checked ? [...cs, r.ligneId] : cs.filter(id => id !== r.ligneId)))} />
                        )}
                      </td>
                      <td className="px-1 py-2 text-center">
                        {premiere ? (
                          <button type="button" onClick={() => repartir(r.ligneId)} title={tr.split} aria-label={tr.split}
                            className="p-1 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded"><SplitSquareHorizontal size={16} /></button>
                        ) : (
                          <button type="button" onClick={() => setRangees(rs => rs.filter(x => x.cle !== r.cle))} title={tr.removeSplit} aria-label={tr.removeSplit}
                            className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded"><X size={16} /></button>
                        )}
                      </td>
                    </tr>
                    {/* Produit suivi par numéro de série : un numéro par unité de stock reçue. */}
                    {suivi === 'serie' && r.quantite > 0 && (
                      <tr className={premiere ? '' : 'bg-gray-50/60 dark:bg-gray-900/20'}>
                        <td colSpan={9} className="px-2 pb-2">
                          <label className="block text-xs text-gray-600 dark:text-gray-300">
                            {tr.serials} — {attendue.ligne.produit?.description ?? ''}
                            <span className={`ml-2 font-medium ${numerosSaisis(r.series).length === attendus ? 'text-green-700' : 'text-amber-700'}`}>{numerosSaisis(r.series).length} / {attendus}</span>
                            <textarea value={r.series} rows={Math.min(6, Math.max(2, attendus))} onChange={e => changer(r.cle, { series: e.target.value })}
                              className={`${classeChamp} font-mono mt-1`} placeholder={tr.serialsHelp} />
                          </label>
                        </td>
                      </tr>
                    )}
                    </Fragment>
                  );
                })}
              </tbody>
            </table>
          </div>
          <p className="text-xs text-gray-500">{tr.closeHelp}</p>

          <CoutsAnnexes frais={frais} onChange={setFrais} devises={devises} tauxParDevise={tauxParDevise} fournisseurs={fournisseurs} codeEntreprise={codeEntreprise} />

          {erreur && <div className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200 whitespace-pre-line">{erreur}</div>}

          <div className="flex flex-wrap gap-3 pt-2">
            <button type="button" onClick={onClose} disabled={enCours} className="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
              {tr.cancel}
            </button>
            <button type="submit" disabled={enCours} className="flex-1 flex items-center justify-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50">
              <Plus size={16} /> {enCours ? tr.saving : tr.save}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
