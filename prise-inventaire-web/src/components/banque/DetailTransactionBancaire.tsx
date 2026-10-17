import { useEffect, useState } from 'react';
import { Ban, X } from 'lucide-react';
import BoutonPiecesJointes from '@/components/BoutonPiecesJointes';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { formatDateDocument, formatDateHeure, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import { annulerTransactionBancaire, getTransactionBancaire, type TransactionBancaire } from '@/services/banque';

const classeChamp = 'w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

/** Détail d'une transaction bancaire : comptes de contrepartie, pièces jointes, annulation avec son motif. */
export default function DetailTransactionBancaire({ id, onClose, onModifiee }: { id: number; onClose: () => void; onModifiee: () => void }) {
  const { t, language } = useLanguage();
  const tb = t.bank;
  const { peut } = usePermissions();
  const montant = useMontantDocument();
  const [transaction, setTransaction] = useState<TransactionBancaire | null>(null);
  const [erreur, setErreur] = useState('');
  const [annulation, setAnnulation] = useState<string | null>(null);
  const [enCours, setEnCours] = useState(false);

  useEffect(() => {
    let actif = true;
    getTransactionBancaire(id).then(tr => { if (actif) setTransaction(tr); }, e => { if (actif) setErreur(messageErreur(e, tb.error)); });
    return () => { actif = false; };
  }, [id, tb.error]);

  async function annuler() {
    if (!transaction || !confirm(tb.confirmCancel.replace('{numero}', transaction.numero))) return;
    setEnCours(true);
    setErreur('');
    try {
      setTransaction(await annulerTransactionBancaire(transaction.id, annulation));
      setAnnulation(null);
      onModifiee();
    } catch (e) {
      setErreur(messageErreur(e, tb.error));
    } finally {
      setEnCours(false);
    }
  }

  const devise = transaction?.devise ?? null;
  const valide = transaction?.statut === 'valide';

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <div role="dialog" aria-modal="true" aria-label={`${tb.transaction} ${transaction?.numero ?? ''}`}
        className="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-2xl max-h-[94vh] overflow-y-auto">
        <div className="flex items-center justify-between gap-3 p-4 border-b dark:border-gray-700">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-lg font-semibold dark:text-white">{tb.transaction} {transaction?.numero}</h2>
            {transaction && (
              <span className={`px-2 py-0.5 rounded text-xs font-medium ${valide ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-200' : 'bg-gray-200 text-gray-500 line-through dark:bg-gray-700 dark:text-gray-400'}`}>
                {tb.statuses[transaction.statut]}
              </span>
            )}
            {transaction && <BoutonPiecesJointes entite="transaction_bancaire" entiteId={transaction.id} titre={transaction.numero} />}
          </div>
          <button type="button" onClick={onClose} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" aria-label={tb.close}><X size={20} /></button>
        </div>
        {!transaction ? <p className="p-4 text-sm text-gray-500">{erreur || tb.loading}</p> : (
          <div className="p-4 space-y-4 text-sm dark:text-gray-200">
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div><p className="text-xs text-gray-500">{tb.type}</p><p className="font-medium">{tb.types[transaction.type]}</p></div>
              <div>
                <p className="text-xs text-gray-500">{transaction.type === 'virement' ? tb.fromAccount : tb.account}</p>
                <p className="font-medium">{transaction.compte_bancaire?.nom}</p>
                {transaction.destination && <p className="text-xs text-gray-500">{tb.toAccount} : {transaction.destination.nom}</p>}
              </div>
              <div><p className="text-xs text-gray-500">{tb.date}</p><p className="font-medium">{formatDateDocument(transaction.date_transaction, language)}</p></div>
            </div>
            <p><span className="text-gray-500">{tb.label} : </span>{transaction.libelle}{transaction.reference ? ` — ${transaction.reference}` : ''}</p>
            {transaction.notes && <p className="text-gray-600 dark:text-gray-300 whitespace-pre-line">{transaction.notes}</p>}
            {!valide && (
              <div className="px-3 py-2 rounded-lg bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">
                {tb.cancelledBy.replace('{date}', formatDateHeure(transaction.annule_le, language)).replace('{nom}', transaction.auteur_annulation?.nom ?? '—')}
                {transaction.motif_annulation && <span> — {tb.reason} : {transaction.motif_annulation}</span>}
              </div>
            )}
            {(transaction.lignes ?? []).length > 0 && (
              <table className="w-full" aria-label={tb.lines}>
                <thead className="text-xs text-gray-500 border-b dark:border-gray-700">
                  <tr><th className="py-1 text-left">{tb.ledgerAccount}</th><th className="py-1 text-left">{tb.lineLabel}</th><th className="py-1 text-right">{tb.amount}</th></tr>
                </thead>
                <tbody className="divide-y dark:divide-gray-700">
                  {(transaction.lignes ?? []).map(l => (
                    <tr key={l.id}>
                      <td className="py-1">{l.compte ? `${l.compte.numero} — ${l.compte.libelle}` : l.compte_comptable_id}</td>
                      <td className="py-1">{l.libelle ?? ''}</td>
                      <td className="py-1 text-right">{montant(l.montant, devise)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
            <p className="text-right"><span className="text-gray-500">{tb.total} : </span><strong>{montant(transaction.montant, devise)}</strong></p>
            {annulation !== null && (
              <label className="block">
                <span className="block text-gray-700 dark:text-gray-300 mb-1">{tb.cancelReason}</span>
                <input value={annulation} maxLength={255} onChange={e => setAnnulation(e.target.value)} className={classeChamp} />
                <span className="block mt-1 text-xs text-gray-500">{tb.cancelHelp}</span>
              </label>
            )}
            {erreur && <div className="px-3 py-2 rounded-lg bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}
            <div className="flex flex-wrap justify-end gap-2">
              {valide && peut('banque', 'annuler') && (annulation === null ? (
                <button type="button" onClick={() => setAnnulation('')}
                  className="flex items-center gap-1 px-4 py-2 border border-red-600 text-red-700 rounded-lg hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-900/30"><Ban size={14} /> {tb.cancelTransaction}</button>
              ) : (
                <button type="button" disabled={enCours} onClick={annuler}
                  className="flex items-center gap-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"><Ban size={14} /> {tb.cancelTransaction}</button>
              ))}
              <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg dark:border-gray-600 dark:text-gray-200">{tb.close}</button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
