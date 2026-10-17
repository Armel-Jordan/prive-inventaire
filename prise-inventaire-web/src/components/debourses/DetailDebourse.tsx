import { useCallback, useEffect, useState } from 'react';
import { Ban, Pencil, X } from 'lucide-react';
import BoutonPdf from '@/components/BoutonPdf';
import BoutonPiecesJointes from '@/components/BoutonPiecesJointes';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { formatDateDocument, formatDateHeure, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import { annulerDebourse, getDebourse, modifierDebourse, type Debourse } from '@/services/debourses';

const classeChamp = 'w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

/**
 * Détail d'un déboursé : factures réglées (escomptes obtenus), avis de paiement imprimable, pièces jointes (copie du
 * chèque, avis de virement) ; correction de la référence et des notes ; annulation avec son motif.
 */
export default function DetailDebourse({ id, onClose, onModifie }: { id: number; onClose: () => void; onModifie: () => void }) {
  const { t, language } = useLanguage();
  const td = t.supplierPayments;
  const { peut } = usePermissions();
  const montant = useMontantDocument();
  const [debourse, setDebourse] = useState<Debourse | null>(null);
  const [erreur, setErreur] = useState('');
  const [enCours, setEnCours] = useState(false);
  const [correction, setCorrection] = useState<{ reference: string; notes: string } | null>(null);
  const [annulation, setAnnulation] = useState<string | null>(null);

  const charger = useCallback(() => getDebourse(id).then(setDebourse, e => setErreur(messageErreur(e, td.error))), [id, td.error]);
  useEffect(() => { charger(); }, [charger]);

  async function executer(action: () => Promise<Debourse>) {
    setEnCours(true);
    setErreur('');
    try {
      setDebourse(await action());
      setCorrection(null);
      setAnnulation(null);
      onModifie();
    } catch (e) {
      setErreur(messageErreur(e, td.error));
    } finally {
      setEnCours(false);
    }
  }

  const devise = debourse?.devise ?? null;
  const valide = debourse?.statut === 'valide';

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <div role="dialog" aria-modal="true" aria-label={`${td.payment} ${debourse?.numero ?? ''}`}
        className="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-3xl max-h-[94vh] overflow-y-auto">
        <div className="flex items-center justify-between gap-3 p-4 border-b dark:border-gray-700 sticky top-0 bg-white dark:bg-gray-800 z-10">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-lg font-semibold dark:text-white">{td.payment} {debourse?.numero}</h2>
            {debourse && (
              <span className={`px-2 py-0.5 rounded text-xs font-medium ${valide ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-200' : 'bg-gray-200 text-gray-500 line-through dark:bg-gray-700 dark:text-gray-400'}`}>
                {td.statuses[debourse.statut]}
              </span>
            )}
            {debourse && <BoutonPdf chemin={`/debourses/${debourse.id}/pdf`} nomFichier={`avis-paiement-${debourse.numero}.pdf`} />}
            {debourse && <BoutonPiecesJointes entite="debourse" entiteId={debourse.id} titre={debourse.numero} />}
          </div>
          <button type="button" onClick={onClose} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" aria-label={td.close}><X size={20} /></button>
        </div>

        {!debourse ? (
          <p className="p-4 text-sm text-gray-500">{erreur || td.loading}</p>
        ) : (
          <div className="p-4 space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm dark:text-gray-200">
              <div>
                <p className="text-xs text-gray-500">{td.supplier}</p>
                <p className="font-medium">{debourse.fournisseur?.raison_sociale}</p>
              </div>
              <div>
                <p className="text-xs text-gray-500">{td.date}</p>
                <p className="font-medium">{formatDateDocument(debourse.date_debourse, language)}</p>
              </div>
              <div>
                <p className="text-xs text-gray-500">{td.mode}</p>
                <p className="font-medium">{td.modes[debourse.mode] ?? debourse.mode}{debourse.reference ? ` — ${debourse.reference}` : ''}</p>
                {debourse.compte_bancaire && <p className="text-xs text-gray-500">{debourse.compte_bancaire.nom}</p>}
                {debourse.auteur && <p className="text-xs text-gray-500">{td.recordedBy.replace('{nom}', debourse.auteur.nom)}</p>}
              </div>
            </div>
            {debourse.notes && <p className="text-sm text-gray-600 dark:text-gray-300 whitespace-pre-line">{debourse.notes}</p>}
            {!valide && (
              <div className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">
                {td.cancelledBy.replace('{date}', formatDateHeure(debourse.annule_le, language)).replace('{nom}', debourse.auteur_annulation?.nom ?? '—')}
                {debourse.motif_annulation && <span> — {td.reason} : {debourse.motif_annulation}</span>}
              </div>
            )}

            <div className="border dark:border-gray-700 rounded-lg overflow-x-auto">
              <table className="w-full text-sm" aria-label={td.paidInvoices}>
                <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                  <tr>
                    <th className="px-3 py-2 text-left">{td.invoice}</th>
                    <th className="px-3 py-2 text-left">{td.invoiceDate}</th>
                    <th className="px-3 py-2 text-right">{td.discount}</th>
                    <th className="px-3 py-2 text-right">{td.paid}</th>
                  </tr>
                </thead>
                <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                  {(debourse.lignes ?? []).map(l => (
                    <tr key={l.id}>
                      <td className="px-3 py-2">
                        <span className="font-mono">{l.facture?.numero}</span>
                        {l.facture?.numero_fournisseur && <span className="block text-xs text-gray-500">{td.theirNumber.replace('{numero}', l.facture.numero_fournisseur)}</span>}
                      </td>
                      <td className="px-3 py-2">{formatDateDocument(l.facture?.date_facture, language)}</td>
                      <td className="px-3 py-2 text-right">{Number(l.escompte) > 0 ? montant(l.escompte, devise) : '—'}</td>
                      <td className="px-3 py-2 text-right">{montant(Number(l.montant) - Number(l.escompte), devise)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <dl className="ml-auto w-fit text-sm dark:text-gray-200 grid grid-cols-[auto_auto] gap-x-4 gap-y-0.5 text-right">
              <dt className="text-gray-500">{td.settled}</dt><dd>{montant(debourse.montant_applique, devise)}</dd>
              {Number(debourse.escompte) > 0 && <><dt className="text-gray-500">{td.discountObtained}</dt><dd>- {montant(debourse.escompte, devise)}</dd></>}
              <dt className="text-gray-500">{td.amountPaid}</dt><dd className="font-semibold">{montant(debourse.montant, devise)}</dd>
            </dl>

            {correction && (
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label className="block text-xs text-gray-500">{td.reference}
                  <input value={correction.reference} maxLength={100} onChange={e => setCorrection({ ...correction, reference: e.target.value })} className={classeChamp} />
                </label>
                <label className="block text-xs text-gray-500 sm:col-span-2">{td.notes}
                  <textarea rows={2} maxLength={2000} value={correction.notes} onChange={e => setCorrection({ ...correction, notes: e.target.value })} className={classeChamp} />
                </label>
              </div>
            )}
            {annulation !== null && (
              <label className="block text-sm">
                <span className="block text-gray-700 dark:text-gray-300 mb-1">{td.cancelReason}</span>
                <input value={annulation} maxLength={255} onChange={e => setAnnulation(e.target.value)} className={classeChamp} />
                <span className="block mt-1 text-xs text-gray-500">{td.cancelHelp}</span>
              </label>
            )}
            {erreur && <div className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}

            <div className="flex flex-wrap justify-end gap-2">
              {correction ? (
                <>
                  <button type="button" onClick={() => setCorrection(null)} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-gray-200">{td.cancel}</button>
                  <button type="button" disabled={enCours}
                    onClick={() => executer(() => modifierDebourse(debourse.id, { reference: correction.reference.trim() || null, notes: correction.notes.trim() || null }))}
                    className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">{td.save}</button>
                </>
              ) : annulation !== null ? (
                <>
                  <button type="button" onClick={() => setAnnulation(null)} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-gray-200">{td.close}</button>
                  <button type="button" disabled={enCours}
                    onClick={() => confirm(td.confirmCancel.replace('{numero}', debourse.numero)) && executer(() => annulerDebourse(debourse.id, annulation))}
                    className="flex items-center gap-1 px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"><Ban size={14} /> {td.cancelPayment}</button>
                </>
              ) : (
                <>
                  {valide && peut('debourses', 'modifier') && (
                    <button type="button" onClick={() => setCorrection({ reference: debourse.reference ?? '', notes: debourse.notes ?? '' })}
                      className="flex items-center gap-1 px-4 py-2 text-sm border border-blue-600 text-blue-700 rounded-lg hover:bg-blue-50 dark:text-blue-300 dark:hover:bg-blue-900/30"><Pencil size={14} /> {td.edit}</button>
                  )}
                  {valide && peut('debourses', 'annuler') && (
                    <button type="button" onClick={() => setAnnulation('')}
                      className="flex items-center gap-1 px-4 py-2 text-sm border border-red-600 text-red-700 rounded-lg hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-900/30"><Ban size={14} /> {td.cancelPayment}</button>
                  )}
                  <button type="button" onClick={onClose} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-gray-200">{td.close}</button>
                </>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
