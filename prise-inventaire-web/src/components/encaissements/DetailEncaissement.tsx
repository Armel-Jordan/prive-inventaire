import { useCallback, useEffect, useState } from 'react';
import { Ban, Pencil, X } from 'lucide-react';
import BoutonPdf from '@/components/BoutonPdf';
import BoutonPiecesJointes from '@/components/BoutonPiecesJointes';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { formatDateDocument, formatDateHeure, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import { annulerEncaissement, getEncaissement, modifierEncaissement, type Encaissement } from '@/services/encaissements';

const classeChamp = 'w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

/**
 * Détail d'un encaissement : factures réglées (escompte compris), surplus laissé en acompte, reçu imprimable, pièces
 * jointes (chèque numérisé, avis de virement) ; correction de la référence et des notes ; annulation avec son motif.
 */
export default function DetailEncaissement({ id, onClose, onModifie }: { id: number; onClose: () => void; onModifie: () => void }) {
  const { t, language } = useLanguage();
  const te = t.customerPayments;
  const { peut } = usePermissions();
  const montant = useMontantDocument();
  const [encaissement, setEncaissement] = useState<Encaissement | null>(null);
  const [erreur, setErreur] = useState('');
  const [enCours, setEnCours] = useState(false);
  const [correction, setCorrection] = useState<{ reference: string; notes: string } | null>(null);
  const [annulation, setAnnulation] = useState<string | null>(null);

  const charger = useCallback(() => getEncaissement(id).then(setEncaissement, e => setErreur(messageErreur(e, te.error))), [id, te.error]);
  useEffect(() => { charger(); }, [charger]);

  async function executer(action: () => Promise<Encaissement>) {
    setEnCours(true);
    setErreur('');
    try {
      setEncaissement(await action());
      setCorrection(null);
      setAnnulation(null);
      onModifie();
    } catch (e) {
      setErreur(messageErreur(e, te.error));
    } finally {
      setEnCours(false);
    }
  }

  const devise = encaissement?.devise ?? null;
  const valide = encaissement?.statut === 'valide';

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <div role="dialog" aria-modal="true" aria-label={`${te.payment} ${encaissement?.numero ?? ''}`}
        className="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-3xl max-h-[94vh] overflow-y-auto">
        <div className="flex items-center justify-between gap-3 p-4 border-b dark:border-gray-700 sticky top-0 bg-white dark:bg-gray-800 z-10">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-lg font-semibold dark:text-white">{te.payment} {encaissement?.numero}</h2>
            {encaissement && (
              <span className={`px-2 py-0.5 rounded text-xs font-medium ${valide ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-200' : 'bg-gray-200 text-gray-500 line-through dark:bg-gray-700 dark:text-gray-400'}`}>
                {te.statuses[encaissement.statut]}
              </span>
            )}
            {encaissement && <BoutonPdf chemin={`/encaissements/${encaissement.id}/pdf`} nomFichier={`recu-${encaissement.numero}.pdf`} />}
            {encaissement && <BoutonPiecesJointes entite="encaissement" entiteId={encaissement.id} titre={encaissement.numero} />}
          </div>
          <button type="button" onClick={onClose} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" aria-label={te.close}><X size={20} /></button>
        </div>

        {!encaissement ? (
          <p className="p-4 text-sm text-gray-500">{erreur || te.loading}</p>
        ) : (
          <div className="p-4 space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm dark:text-gray-200">
              <div>
                <p className="text-xs text-gray-500">{te.customer}</p>
                <p className="font-medium">{encaissement.client?.raison_sociale}</p>
              </div>
              <div>
                <p className="text-xs text-gray-500">{te.date}</p>
                <p className="font-medium">{formatDateDocument(encaissement.date_encaissement, language)}</p>
              </div>
              <div>
                <p className="text-xs text-gray-500">{te.mode}</p>
                <p className="font-medium">{te.modes[encaissement.mode] ?? encaissement.mode}{encaissement.reference ? ` — ${encaissement.reference}` : ''}</p>
                {encaissement.compte_bancaire && <p className="text-xs text-gray-500">{encaissement.compte_bancaire.nom}</p>}
                {encaissement.auteur && <p className="text-xs text-gray-500">{te.recordedBy.replace('{nom}', encaissement.auteur.nom)}</p>}
              </div>
            </div>
            {encaissement.notes && <p className="text-sm text-gray-600 dark:text-gray-300 whitespace-pre-line">{encaissement.notes}</p>}
            {!valide && (
              <div className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">
                {te.cancelledBy.replace('{date}', formatDateHeure(encaissement.annule_le, language)).replace('{nom}', encaissement.auteur_annulation?.nom ?? '—')}
                {encaissement.motif_annulation && <span> — {te.reason} : {encaissement.motif_annulation}</span>}
              </div>
            )}

            {(encaissement.lignes ?? []).length > 0 && (
              <div className="border dark:border-gray-700 rounded-lg overflow-x-auto">
                <table className="w-full text-sm" aria-label={te.paidInvoices}>
                  <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                    <tr>
                      <th className="px-3 py-2 text-left">{te.invoice}</th>
                      <th className="px-3 py-2 text-left">{te.invoiceDate}</th>
                      <th className="px-3 py-2 text-right">{te.discount}</th>
                      <th className="px-3 py-2 text-right">{te.applied}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                    {(encaissement.lignes ?? []).map(l => (
                      <tr key={l.id}>
                        <td className="px-3 py-2 font-mono">{l.facture?.numero}</td>
                        <td className="px-3 py-2">{formatDateDocument(l.facture?.date_facture, language)}</td>
                        <td className="px-3 py-2 text-right">{Number(l.escompte) > 0 ? montant(l.escompte, devise) : '—'}</td>
                        <td className="px-3 py-2 text-right">{montant(l.montant, devise)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="text-sm text-gray-600 dark:text-gray-300 flex-1 min-w-[220px]">
                {Number(encaissement.surplus) > 0 && (
                  <p>
                    {te.surplusKept.replace('{montant}', montant(encaissement.surplus, devise))}
                    {encaissement.acompte && ` ${te.surplusAvailable.replace('{montant}', montant(encaissement.acompte.disponible, devise))}`}
                  </p>
                )}
              </div>
              <dl className="text-sm dark:text-gray-200 grid grid-cols-[auto_auto] gap-x-4 gap-y-0.5 text-right">
                <dt className="text-gray-500">{te.distributed}</dt><dd>{montant(encaissement.montant_applique, devise)}</dd>
                {Number(encaissement.escompte) > 0 && <><dt className="text-gray-500">{te.discount}</dt><dd>- {montant(encaissement.escompte, devise)}</dd></>}
                {Number(encaissement.surplus) > 0 && <><dt className="text-gray-500">{te.surplus}</dt><dd>{montant(encaissement.surplus, devise)}</dd></>}
                <dt className="text-gray-500">{te.amountReceived}</dt><dd className="font-semibold">{montant(encaissement.montant, devise)}</dd>
              </dl>
            </div>

            {correction && (
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label className="block text-xs text-gray-500">{te.reference}
                  <input value={correction.reference} maxLength={100} onChange={e => setCorrection({ ...correction, reference: e.target.value })} className={classeChamp} />
                </label>
                <label className="block text-xs text-gray-500 sm:col-span-2">{te.notes}
                  <textarea rows={2} maxLength={2000} value={correction.notes} onChange={e => setCorrection({ ...correction, notes: e.target.value })} className={classeChamp} />
                </label>
              </div>
            )}
            {annulation !== null && (
              <label className="block text-sm">
                <span className="block text-gray-700 dark:text-gray-300 mb-1">{te.cancelReason}</span>
                <input value={annulation} maxLength={255} onChange={e => setAnnulation(e.target.value)} className={classeChamp} />
                <span className="block mt-1 text-xs text-gray-500">{te.cancelHelp}</span>
              </label>
            )}
            {erreur && <div className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}

            <div className="flex flex-wrap justify-end gap-2">
              {correction ? (
                <>
                  <button type="button" onClick={() => setCorrection(null)} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-gray-200">{te.cancel}</button>
                  <button type="button" disabled={enCours}
                    onClick={() => executer(() => modifierEncaissement(encaissement.id, { reference: correction.reference.trim() || null, notes: correction.notes.trim() || null }))}
                    className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">{te.save}</button>
                </>
              ) : annulation !== null ? (
                <>
                  <button type="button" onClick={() => setAnnulation(null)} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-gray-200">{te.close}</button>
                  <button type="button" disabled={enCours}
                    onClick={() => confirm(te.confirmCancel.replace('{numero}', encaissement.numero)) && executer(() => annulerEncaissement(encaissement.id, annulation))}
                    className="flex items-center gap-1 px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"><Ban size={14} /> {te.cancelPayment}</button>
                </>
              ) : (
                <>
                  {valide && peut('encaissements', 'modifier') && (
                    <button type="button" onClick={() => setCorrection({ reference: encaissement.reference ?? '', notes: encaissement.notes ?? '' })}
                      className="flex items-center gap-1 px-4 py-2 text-sm border border-blue-600 text-blue-700 rounded-lg hover:bg-blue-50 dark:text-blue-300 dark:hover:bg-blue-900/30"><Pencil size={14} /> {te.edit}</button>
                  )}
                  {valide && peut('encaissements', 'annuler') && (
                    <button type="button" onClick={() => setAnnulation('')}
                      className="flex items-center gap-1 px-4 py-2 text-sm border border-red-600 text-red-700 rounded-lg hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-900/30"><Ban size={14} /> {te.cancelPayment}</button>
                  )}
                  <button type="button" onClick={onClose} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-gray-200">{te.close}</button>
                </>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
