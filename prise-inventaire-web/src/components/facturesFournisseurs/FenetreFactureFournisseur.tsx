import { useEffect, useMemo, useState } from 'react';
import { Plus, X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { aujourdhui, dateSaisie, tauxPropose, useCodeDevise, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import { getFournisseursActifs, getParametres, getReferentiel, type FicheReferentiel, type Fournisseur } from '@/services/api';
import {
  enregistrerFactureFournisseur, type FactureFournisseur, type PropositionFacture,
} from '@/services/facturesFournisseurs';

/** Ligne en cours de saisie (nombres en texte, comme les champs). */
interface LigneSaisie {
  cle: string;
  produit_id: number | null;
  description: string;
  quantite: string;
  prix_unitaire: string;
  taux_tva: string;
  reception_ligne_id: number | null;
}

const classeChamp = 'w-full px-2 py-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';
const classeLibelle = 'block text-sm text-gray-700 dark:text-gray-300 mb-1';

let compteur = 0;
const cle = () => `l${++compteur}`;

/**
 * Saisie d'une facture fournisseur : nouvelle (à la main, ou proposée depuis des réceptions), ou modifiée tant qu'elle n'a
 * reçu aucun paiement. Fournisseur, numéro chez lui, dates, condition de paiement, devise, lignes (quantité, prix, taxe)
 * et totaux ; le serveur recalcule les montants.
 */
export default function FenetreFactureFournisseur({ facture, proposition, onClose, onEnregistree }: {
  facture?: FactureFournisseur;
  proposition?: PropositionFacture;
  onClose: () => void;
  onEnregistree: (facture: FactureFournisseur) => void;
}) {
  const { t } = useLanguage();
  const tf = t.supplierInvoices;
  const montant = useMontantDocument();
  const codeEntreprise = useCodeDevise();
  const [fournisseurs, setFournisseurs] = useState<Fournisseur[]>([]);
  const [conditions, setConditions] = useState<FicheReferentiel[]>([]);
  const [tauxDefaut, setTauxDefaut] = useState('');
  const [fournisseurId, setFournisseurId] = useState<number | null>(facture?.fournisseur_id ?? proposition?.fournisseur_id ?? null);
  const [numeroFournisseur, setNumeroFournisseur] = useState(facture?.numero_fournisseur ?? '');
  const [dateFacture, setDateFacture] = useState(facture ? dateSaisie(facture.date_facture) : aujourdhui());
  const [dateEcheance, setDateEcheance] = useState(facture ? dateSaisie(facture.date_echeance) : '');
  const [conditionId, setConditionId] = useState(facture?.condition_paiement_id ? String(facture.condition_paiement_id) : '');
  const [devise, setDevise] = useState(facture?.devise ?? proposition?.devise ?? '');
  const [taux, setTaux] = useState(String(Number(facture?.taux_change ?? proposition?.taux_change ?? 1)));
  const [notes, setNotes] = useState(facture?.notes ?? '');
  const receptions = facture?.receptions ?? proposition?.receptions ?? [];
  const [lignes, setLignes] = useState<LigneSaisie[]>(() => (facture?.lignes ?? proposition?.lignes ?? []).map(l => ({
    cle: cle(),
    produit_id: l.produit_id ?? null,
    description: l.description,
    quantite: String(Number(l.quantite)),
    prix_unitaire: String(Number(l.prix_unitaire)),
    taux_tva: 'taux_tva' in l && l.taux_tva !== null && l.taux_tva !== undefined ? String(Number(l.taux_tva)) : '',
    reception_ligne_id: l.reception_ligne_id ?? null,
  })));
  const [erreur, setErreur] = useState('');
  const [enCours, setEnCours] = useState(false);

  useEffect(() => {
    let actif = true;
    getFournisseursActifs().then(f => { if (actif) setFournisseurs(f ?? []); }, () => {});
    getReferentiel('conditions_paiement', true).then(c => { if (actif) setConditions(c ?? []); }, () => {});
    // Taux de taxe de l'entreprise : celui des lignes ajoutées et des lignes proposées sans taux.
    getParametres().then(p => {
      if (!actif) return;
      const defaut = p.tva_taux !== undefined && p.tva_taux !== null ? String(Number(p.tva_taux)) : '';
      setTauxDefaut(defaut);
      setLignes(ls => ls.map(l => (l.taux_tva === '' ? { ...l, taux_tva: defaut } : l)));
    }, () => {});
    return () => { actif = false; };
  }, []);

  const fournisseur = fournisseurs.find(f => f.id === fournisseurId);
  // Devises proposées : celle de l'entreprise, celle du fournisseur et celle de la facture.
  const devises = useMemo(() => [...new Set([fournisseur?.devise, devise].filter((d): d is string => !!d && d !== codeEntreprise))], [fournisseur, devise, codeEntreprise]);
  const etrangere = devise !== '' && devise !== codeEntreprise;
  const totaux = lignes.reduce((s, l) => {
    const ht = Math.round((Number(l.quantite) || 0) * (Number(l.prix_unitaire) || 0) * 100) / 100;
    const tva = Math.round(ht * (Number(l.taux_tva) || 0)) / 100;
    return { ht: s.ht + ht, tva: s.tva + tva };
  }, { ht: 0, tva: 0 });
  const enDevise = (valeur: number) => montant(valeur, etrangere ? devise : null);

  const changer = (cleLigne: string, champs: Partial<LigneSaisie>) => setLignes(ls => ls.map(l => (l.cle === cleLigne ? { ...l, ...champs } : l)));

  async function changerDevise(choisie: string) {
    setDevise(choisie);
    if (choisie === '' || choisie === codeEntreprise) { setTaux('1'); return; }
    setTaux(await tauxPropose(choisie, codeEntreprise));
  }

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    if (!fournisseurId) return;
    setErreur('');
    setEnCours(true);
    try {
      onEnregistree(await enregistrerFactureFournisseur({
        fournisseur_id: fournisseurId,
        numero_fournisseur: numeroFournisseur.trim() || null,
        date_facture: dateFacture,
        date_echeance: dateEcheance || null,
        condition_paiement_id: conditionId ? Number(conditionId) : null,
        devise: etrangere ? devise : null,
        taux_change: etrangere ? Number(taux) : null,
        notes: notes.trim() || null,
        receptions: receptions.map(r => r.id),
        lignes: lignes.map(l => ({
          produit_id: l.produit_id,
          description: l.description.trim(),
          quantite: Number(l.quantite),
          prix_unitaire: Number(l.prix_unitaire),
          taux_tva: l.taux_tva === '' ? null : Number(l.taux_tva),
          reception_ligne_id: l.reception_ligne_id,
        })),
      }, facture?.id));
    } catch (erreurApi) {
      setErreur(messageErreur(erreurApi, tf.error));
    } finally {
      setEnCours(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <div role="dialog" aria-modal="true" aria-label={facture ? tf.editTitle.replace('{numero}', facture.numero) : tf.formTitle}
        className="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-5xl max-h-[94vh] overflow-y-auto">
        <div className="flex items-center justify-between p-4 border-b dark:border-gray-700 sticky top-0 bg-white dark:bg-gray-800 z-10">
          <h2 className="text-lg font-semibold dark:text-white">{facture ? tf.editTitle.replace('{numero}', facture.numero) : tf.formTitle}</h2>
          <button type="button" onClick={onClose} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" aria-label={tf.close}><X size={20} /></button>
        </div>
        <form onSubmit={enregistrer} className="p-4 space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <label className="block text-sm">
              <span className={classeLibelle}>{tf.supplier} *</span>
              <select value={fournisseurId ?? ''} required disabled={receptions.length > 0} onChange={e => setFournisseurId(e.target.value ? Number(e.target.value) : null)} className={classeChamp}>
                <option value="">{tf.chooseSupplier}</option>
                {fournisseurs.map(f => <option key={f.id} value={f.id}>{f.raison_sociale}</option>)}
                {fournisseurId && !fournisseur && <option value={fournisseurId}>{facture?.fournisseur?.raison_sociale ?? `#${fournisseurId}`}</option>}
              </select>
            </label>
            <label className="block text-sm">
              <span className={classeLibelle}>{tf.supplierNumber}</span>
              <input value={numeroFournisseur} maxLength={60} onChange={e => setNumeroFournisseur(e.target.value)} className={classeChamp} />
            </label>
            <label className="block text-sm">
              <span className={classeLibelle}>{tf.date} *</span>
              <input type="date" value={dateFacture} required onChange={e => setDateFacture(e.target.value)} className={classeChamp} />
            </label>
            <label className="block text-sm">
              <span className={classeLibelle}>{tf.paymentTerms}</span>
              <select value={conditionId} onChange={e => setConditionId(e.target.value)} className={classeChamp}>
                <option value="">{tf.defaultTerms}</option>
                {conditions.map(c => <option key={c.id} value={c.id}>{String(c.libelle ?? c.code)}</option>)}
              </select>
            </label>
            <label className="block text-sm">
              <span className={classeLibelle}>{tf.dueDate}</span>
              <input type="date" value={dateEcheance} min={dateFacture} onChange={e => setDateEcheance(e.target.value)} className={classeChamp} title={tf.dueDateHelp} />
              <span className="text-[11px] text-gray-500">{tf.dueDateHelp}</span>
            </label>
            <label className="block text-sm">
              <span className={classeLibelle}>{tf.currency}</span>
              <div className="flex items-center gap-1">
                <select value={etrangere ? devise : ''} onChange={e => changerDevise(e.target.value)} className={classeChamp}>
                  <option value="">{codeEntreprise || '—'}</option>
                  {devises.map(d => <option key={d} value={d}>{d}</option>)}
                </select>
                {etrangere && (
                  <input type="number" min={0} step="any" value={taux} required aria-label={`${tf.rate} ${devise}`} title={`1 ${devise} = ? ${codeEntreprise}`}
                    onChange={e => setTaux(e.target.value)} className={`${classeChamp} w-28`} />
                )}
              </div>
            </label>
            <label className="block text-sm sm:col-span-2">
              <span className={classeLibelle}>{tf.notes}</span>
              <input value={notes} maxLength={2000} onChange={e => setNotes(e.target.value)} className={classeChamp} />
            </label>
          </div>

          {receptions.length > 0 && (
            <p className="text-sm text-gray-600 dark:text-gray-300">
              {tf.receipts} : {receptions.map(r => r.numero + (r.bon_livraison ? ` (${r.bon_livraison})` : '')).join(', ')}
            </p>
          )}

          <div className="border dark:border-gray-700 rounded-lg overflow-x-auto">
            <table className="w-full min-w-[760px] text-sm" aria-label={tf.lines}>
              <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                <tr>
                  <th className="px-2 py-2 text-left">{tf.description}</th>
                  <th className="px-2 py-2 text-right w-24">{tf.quantity}</th>
                  <th className="px-2 py-2 text-right w-32">{tf.unitPrice}</th>
                  <th className="px-2 py-2 text-right w-20">{tf.tax}</th>
                  <th className="px-2 py-2 text-right w-32">{tf.amount}</th>
                  <th className="w-8" />
                </tr>
              </thead>
              <tbody className="divide-y dark:divide-gray-700">
                {lignes.map(l => (
                  <tr key={l.cle}>
                    <td className="px-2 py-1">
                      <input value={l.description} required maxLength={255} aria-label={tf.description} onChange={e => changer(l.cle, { description: e.target.value })} className={classeChamp} />
                    </td>
                    <td className="px-2 py-1">
                      <input type="number" min={0} step="any" required value={l.quantite} aria-label={tf.quantity} onChange={e => changer(l.cle, { quantite: e.target.value })} className={`${classeChamp} text-right`} />
                    </td>
                    <td className="px-2 py-1">
                      <input type="number" min={0} step="any" required value={l.prix_unitaire} aria-label={tf.unitPrice} onChange={e => changer(l.cle, { prix_unitaire: e.target.value })} className={`${classeChamp} text-right`} />
                    </td>
                    <td className="px-2 py-1">
                      <input type="number" min={0} max={100} step="any" value={l.taux_tva} aria-label={tf.tax} onChange={e => changer(l.cle, { taux_tva: e.target.value })} className={`${classeChamp} text-right`} />
                    </td>
                    <td className="px-2 py-1 text-right dark:text-gray-200">{enDevise((Number(l.quantite) || 0) * (Number(l.prix_unitaire) || 0))}</td>
                    <td className="px-1 py-1 text-center">
                      <button type="button" onClick={() => setLignes(ls => ls.filter(x => x.cle !== l.cle))} title={tf.removeLine} aria-label={tf.removeLine}
                        className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded"><X size={16} /></button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="flex flex-wrap items-start justify-between gap-3">
            <button type="button" onClick={() => setLignes(ls => [...ls, { cle: cle(), produit_id: null, description: '', quantite: '1', prix_unitaire: '0', taux_tva: tauxDefaut, reception_ligne_id: null }])}
              className="flex items-center gap-1 px-3 py-1.5 text-sm rounded-lg border border-blue-600 text-blue-700 hover:bg-blue-50 dark:text-blue-300 dark:hover:bg-blue-900/30">
              <Plus size={14} /> {tf.addLine}
            </button>
            <div className="text-right text-sm dark:text-gray-200 space-y-0.5">
              <div><span className="text-gray-500">{tf.subtotal} : </span>{enDevise(totaux.ht)}</div>
              <div><span className="text-gray-500">{tf.taxes} : </span>{enDevise(totaux.tva)}</div>
              <div><span className="text-gray-500">{tf.totalTtc} : </span><strong>{enDevise(totaux.ht + totaux.tva)}</strong></div>
            </div>
          </div>

          {erreur && <div className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200 whitespace-pre-line">{erreur}</div>}

          <div className="flex flex-wrap gap-3">
            <button type="button" onClick={onClose} disabled={enCours} className="flex-1 px-4 py-2 border rounded-lg dark:border-gray-600 dark:text-gray-200">{tf.cancel}</button>
            <button type="submit" disabled={enCours || lignes.length === 0 || !fournisseurId} className="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">
              {enCours ? tf.saving : tf.save}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
