import { useCallback, useEffect, useState } from 'react';
import { Ban, Banknote, Pencil, Scale, Trash2, X } from 'lucide-react';
import BoutonPiecesJointes from '@/components/BoutonPiecesJointes';
import FenetreFactureFournisseur from '@/components/facturesFournisseurs/FenetreFactureFournisseur';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { aujourdhui, formatDateDocument, formatDateHeure, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import {
  alignerCoutsFacture, annulerFactureFournisseur, annulerPaiementFournisseur, getFactureFournisseur, payerFactureFournisseur,
  type FactureFournisseur, type ModePaiementFournisseur,
} from '@/services/facturesFournisseurs';

const MODES: ModePaiementFournisseur[] = ['virement', 'cheque', 'especes', 'carte', 'autre'];
const classeChamp = 'w-full px-2 py-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

/** Pastille de l'état de paiement d'une facture fournisseur. */
export function BadgeEtatFactureFournisseur({ facture, libelles, retard }: { facture: Pick<FactureFournisseur, 'etat' | 'jours_retard'>; libelles: Record<string, string>; retard: string }) {
  const couleurs: Record<string, string> = {
    impayee: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
    partielle: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    payee: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-200',
    en_retard: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-200',
    annulee: 'bg-gray-200 text-gray-500 line-through dark:bg-gray-700 dark:text-gray-400',
  };
  return (
    <span className={`px-2 py-0.5 rounded text-xs font-medium whitespace-nowrap ${couleurs[facture.etat] ?? ''}`}>
      {libelles[facture.etat] ?? facture.etat}{facture.etat === 'en_retard' && facture.jours_retard > 0 ? ` · ${retard.replace('{n}', String(facture.jours_retard))}` : ''}
    </span>
  );
}

/**
 * Détail d'une facture fournisseur : lignes et rapprochement avec la marchandise reçue (écart, alignement des coûts),
 * taxes, paiements (enregistrer, annuler), modification tant qu'aucun paiement n'est versé, annulation, pièces jointes.
 */
export default function DetailFactureFournisseur({ id, onClose, onModifiee }: { id: number; onClose: () => void; onModifiee: () => void }) {
  const { t, language } = useLanguage();
  const tf = t.supplierInvoices;
  const { peut } = usePermissions();
  const montant = useMontantDocument();
  const [facture, setFacture] = useState<FactureFournisseur | null>(null);
  const [erreur, setErreur] = useState('');
  const [message, setMessage] = useState('');
  const [enCours, setEnCours] = useState(false);
  const [modification, setModification] = useState(false);
  const [annulation, setAnnulation] = useState<string | null>(null);
  const [paiement, setPaiement] = useState({ date: aujourdhui(), montant: '', mode: 'virement' as ModePaiementFournisseur, reference: '' });

  const charger = useCallback(() => getFactureFournisseur(id).then(f => {
    setFacture(f);
    setPaiement(p => ({ ...p, montant: f.reste_a_payer > 0 ? String(f.reste_a_payer) : '' }));
  }, e => setErreur(messageErreur(e, tf.error))), [id, tf.error]);
  useEffect(() => { charger(); }, [charger]);

  // L'action peut renvoyer un message de succès avec la facture (alignement des coûts).
  async function executer(action: () => Promise<FactureFournisseur | { facture: FactureFournisseur; message: string }>) {
    setEnCours(true);
    setErreur('');
    setMessage('');
    try {
      const resultat = await action();
      const f = 'facture' in resultat ? resultat.facture : resultat;
      setFacture(f);
      setPaiement(p => ({ ...p, montant: f.reste_a_payer > 0 ? String(f.reste_a_payer) : '', reference: '' }));
      setAnnulation(null);
      if ('message' in resultat) setMessage(resultat.message);
      onModifiee();
    } catch (e) {
      setErreur(messageErreur(e, tf.error));
    } finally {
      setEnCours(false);
    }
  }

  if (modification && facture) {
    return <FenetreFactureFournisseur facture={facture} onClose={() => setModification(false)}
      onEnregistree={f => { setModification(false); setFacture(f); onModifiee(); }} />;
  }

  const devise = facture?.devise ?? null;
  const ouverte = facture?.statut === 'enregistree';
  const sansPaiement = Number(facture?.montant_paye ?? 0) <= 0;

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <div role="dialog" aria-modal="true" aria-label={`${tf.formTitle} ${facture?.numero ?? ''}`}
        className="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-5xl max-h-[94vh] overflow-y-auto">
        <div className="flex items-center justify-between gap-3 p-4 border-b dark:border-gray-700 sticky top-0 bg-white dark:bg-gray-800 z-10">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-lg font-semibold dark:text-white">{tf.formTitle} {facture?.numero}</h2>
            {facture && <BadgeEtatFactureFournisseur facture={facture} libelles={tf.states} retard={tf.lateDays} />}
            {facture && <BoutonPiecesJointes entite="facture_fournisseur" entiteId={facture.id} titre={facture.numero} />}
          </div>
          <button type="button" onClick={onClose} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" aria-label={tf.close}><X size={20} /></button>
        </div>

        {!facture ? (
          <p className="p-4 text-sm text-gray-500">{erreur || tf.loading}</p>
        ) : (
          <div className="p-4 space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm dark:text-gray-200">
              <div>
                <p className="text-xs text-gray-500">{tf.supplier}</p>
                <p className="font-medium">{facture.fournisseur?.raison_sociale}</p>
                {facture.numero_fournisseur && <p className="text-xs text-gray-500">{tf.supplierNumber} : {facture.numero_fournisseur}</p>}
              </div>
              <div>
                <p className="text-xs text-gray-500">{tf.date}</p>
                <p className="font-medium">{formatDateDocument(facture.date_facture, language)}</p>
              </div>
              <div>
                <p className="text-xs text-gray-500">{tf.dueDate}</p>
                <p className="font-medium">{formatDateDocument(facture.date_echeance, language)}</p>
                {facture.condition && <p className="text-xs text-gray-500">{facture.condition.libelle}</p>}
              </div>
              <div>
                <p className="text-xs text-gray-500">{tf.receipts}</p>
                <p>{facture.receptions?.length ? facture.receptions.map(r => r.numero).join(', ') : '—'}</p>
              </div>
            </div>
            {facture.notes && <p className="text-sm text-gray-600 dark:text-gray-300 whitespace-pre-line">{facture.notes}</p>}
            {facture.statut === 'annulee' && (
              <div className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">
                {tf.cancelledBy.replace('{date}', formatDateHeure(facture.annulee_le, language)).replace('{nom}', facture.auteur_annulation?.nom ?? '—')}
                {facture.motif_annulation && <span> — {tf.reason} : {facture.motif_annulation}</span>}
              </div>
            )}

            <div className="border dark:border-gray-700 rounded-lg overflow-x-auto">
              <table className="w-full min-w-[760px] text-sm" aria-label={tf.lines}>
                <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                  <tr>
                    <th className="px-3 py-2 text-left">{tf.description}</th>
                    <th className="px-3 py-2 text-right">{tf.quantity}</th>
                    <th className="px-3 py-2 text-right">{tf.unitPrice}</th>
                    <th className="px-3 py-2 text-right">{tf.amount}</th>
                    <th className="px-3 py-2 text-right">{tf.tax}</th>
                    <th className="px-3 py-2 text-right">{tf.received}</th>
                    <th className="px-3 py-2 text-right">{tf.gap}</th>
                  </tr>
                </thead>
                <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                  {(facture.lignes ?? []).map(l => (
                    <tr key={l.id}>
                      <td className="px-3 py-2">{l.description}</td>
                      <td className="px-3 py-2 text-right">{Number(l.quantite)}</td>
                      <td className="px-3 py-2 text-right">{montant(l.prix_unitaire, devise)}</td>
                      <td className="px-3 py-2 text-right">{montant(l.montant_ht, devise)}</td>
                      <td className="px-3 py-2 text-right">{Number(l.taux_tva)} %</td>
                      <td className="px-3 py-2 text-right">{l.valeur_recue !== undefined ? montant(l.valeur_recue, devise) : '—'}</td>
                      <td className={`px-3 py-2 text-right ${l.ecart ? 'text-red-600 font-medium' : ''}`}>{l.ecart !== undefined ? montant(l.ecart, devise) : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="text-sm space-y-1">
                {facture.ecart !== null && facture.ecart !== undefined && (
                  <p className={facture.ecart ? 'text-red-700 dark:text-red-300' : 'text-gray-600 dark:text-gray-300'}>
                    {tf.gapTotal.replace('{montant}', montant(facture.ecart, devise))}
                  </p>
                )}
                {!!facture.ecart && ouverte && peut('factures_fournisseurs', 'modifier') && (
                  <button type="button" disabled={enCours} title={tf.alignHelp}
                    onClick={() => executer(() => alignerCoutsFacture(facture.id))}
                    className="flex items-center gap-1 px-3 py-1.5 text-sm rounded-lg border border-amber-600 text-amber-800 hover:bg-amber-50 dark:text-amber-200 dark:hover:bg-amber-900/30 disabled:opacity-50">
                    <Scale size={14} /> {tf.alignCosts}
                  </button>
                )}
              </div>
              <div className="text-right text-sm dark:text-gray-200 space-y-0.5">
                <div><span className="text-gray-500">{tf.subtotal} : </span>{montant(facture.montant_ht, devise)}</div>
                {(facture.taxes ?? []).map(tx => (
                  <div key={`${tx.nom}${tx.taux}`}><span className="text-gray-500">{tx.nom} ({tx.taux} %) : </span>{montant(tx.montant, devise)}</div>
                ))}
                {(facture.taxes ?? []).length === 0 && <div><span className="text-gray-500">{tf.taxes} : </span>{montant(facture.montant_tva, devise)}</div>}
                <div><span className="text-gray-500">{tf.totalTtc} : </span><strong>{montant(facture.montant_ttc, devise)}</strong></div>
                <div><span className="text-gray-500">{tf.paid} : </span>{montant(facture.montant_paye, devise)}</div>
                <div><span className="text-gray-500">{tf.remaining} : </span><strong>{montant(facture.reste_a_payer, devise)}</strong></div>
              </div>
            </div>

            <div className="border-t dark:border-gray-700 pt-3 space-y-2">
              <h3 className="text-sm font-semibold dark:text-white">{tf.payments}</h3>
              {(facture.paiements ?? []).length === 0 ? <p className="text-sm text-gray-500">{tf.noPayment}</p> : (
                <ul className="text-sm space-y-1 dark:text-gray-200">
                  {(facture.paiements ?? []).map(p => (
                    <li key={p.id} className="flex flex-wrap items-center gap-2">
                      <span className="w-24">{formatDateDocument(p.date_paiement, language)}</span>
                      <span className="w-24">{tf.modes[p.mode] ?? p.mode}</span>
                      <span className="flex-1 text-gray-500">
                        {p.reference ?? ''}{p.auteur ? ` — ${p.auteur.nom}` : ''}
                        {p.debourse && <span className="ml-1 font-mono">({tf.disbursement.replace('{numero}', p.debourse.numero)})</span>}
                      </span>
                      {Number(p.escompte ?? 0) > 0 && <span className="text-xs text-green-700 dark:text-green-300">{tf.discountTaken.replace('{montant}', montant(p.escompte, devise))}</span>}
                      <strong>{montant(p.montant, devise)}</strong>
                      {/* Une part de déboursé s'annule avec son déboursé. */}
                      {ouverte && !p.debourse && peut('factures_fournisseurs', 'payer') && (
                        <button type="button" disabled={enCours} title={tf.cancelPayment} aria-label={tf.cancelPayment}
                          onClick={() => confirm(tf.confirmCancelPayment) && executer(() => annulerPaiementFournisseur(facture.id, p.id))}
                          className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded"><Trash2 size={14} /></button>
                      )}
                    </li>
                  ))}
                </ul>
              )}
              {ouverte && facture.reste_a_payer > 0 && peut('factures_fournisseurs', 'payer') && (
                <form className="grid grid-cols-2 sm:grid-cols-5 gap-2 items-end" aria-label={tf.addPayment}
                  onSubmit={e => { e.preventDefault(); executer(() => payerFactureFournisseur(facture.id, { date_paiement: paiement.date, montant: Number(paiement.montant), mode: paiement.mode, reference: paiement.reference || undefined })); }}>
                  <label className="text-xs text-gray-500">{tf.paymentDate}
                    <input type="date" required value={paiement.date} onChange={e => setPaiement({ ...paiement, date: e.target.value })} className={classeChamp} />
                  </label>
                  <label className="text-xs text-gray-500">{tf.paymentAmount}
                    <input type="number" min={0} step="0.01" required value={paiement.montant} onChange={e => setPaiement({ ...paiement, montant: e.target.value })} className={classeChamp} />
                  </label>
                  <label className="text-xs text-gray-500">{tf.paymentMode}
                    <select value={paiement.mode} onChange={e => setPaiement({ ...paiement, mode: e.target.value as ModePaiementFournisseur })} className={classeChamp}>
                      {MODES.map(m => <option key={m} value={m}>{tf.modes[m]}</option>)}
                    </select>
                  </label>
                  <label className="text-xs text-gray-500">{tf.paymentRef}
                    <input value={paiement.reference} maxLength={60} onChange={e => setPaiement({ ...paiement, reference: e.target.value })} className={classeChamp} />
                  </label>
                  <button type="submit" disabled={enCours} className="flex items-center justify-center gap-1 px-3 py-1.5 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50">
                    <Banknote size={14} /> {tf.pay}
                  </button>
                </form>
              )}
            </div>

            {annulation !== null && (
              <label className="block text-sm">
                <span className="block text-gray-700 dark:text-gray-300 mb-1">{tf.cancelReason}</span>
                <input value={annulation} maxLength={255} onChange={e => setAnnulation(e.target.value)} className={classeChamp} />
              </label>
            )}
            {message && <div className="px-3 py-2 rounded-lg text-sm bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-200" role="status">{message}</div>}
            {erreur && <div className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}

            <div className="flex flex-wrap justify-end gap-2">
              {annulation !== null ? (
                <>
                  <button type="button" onClick={() => setAnnulation(null)} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-gray-200">{tf.close}</button>
                  <button type="button" disabled={enCours}
                    onClick={() => confirm(tf.confirmCancel.replace('{numero}', facture.numero)) && executer(() => annulerFactureFournisseur(facture.id, annulation))}
                    className="flex items-center gap-1 px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"><Ban size={14} /> {tf.cancelInvoice}</button>
                </>
              ) : (
                <>
                  {ouverte && sansPaiement && peut('factures_fournisseurs', 'modifier') && (
                    <button type="button" onClick={() => setModification(true)} className="flex items-center gap-1 px-4 py-2 text-sm border border-blue-600 text-blue-700 rounded-lg hover:bg-blue-50 dark:text-blue-300 dark:hover:bg-blue-900/30"><Pencil size={14} /> {tf.edit}</button>
                  )}
                  {ouverte && sansPaiement && peut('factures_fournisseurs', 'annuler') && (
                    <button type="button" onClick={() => setAnnulation('')} className="flex items-center gap-1 px-4 py-2 text-sm border border-red-600 text-red-700 rounded-lg hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-900/30"><Ban size={14} /> {tf.cancelInvoice}</button>
                  )}
                  <button type="button" onClick={onClose} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-gray-200">{tf.close}</button>
                </>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
