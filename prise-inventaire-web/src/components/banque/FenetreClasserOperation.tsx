import { useEffect, useState } from 'react';
import { X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { formatDateDocument, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import { classerOperation, type OperationReleve, type TransactionBancaire } from '@/services/banque';
import { getComptes, type CompteComptable } from '@/services/comptabilite';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

/**
 * Opération du relevé sans mouvement (frais, intérêts, prélèvement) : sa transaction bancaire — un dépôt si l'argent
 * entre, un retrait s'il sort — est créée sur le compte de contrepartie choisi, passée au journal et reliée.
 */
export default function FenetreClasserOperation({ operation, compteBanqueComptable, onClose, onClassee }: {
  operation: OperationReleve;
  /** Compte comptable du compte bancaire, écarté des contreparties. */
  compteBanqueComptable: number | null;
  onClose: () => void;
  onClassee: (resultat: { ligne: OperationReleve; transaction: TransactionBancaire; message: string }) => void;
}) {
  const { t, language } = useLanguage();
  const tb = t.bank;
  const ts = t.bank.statements;
  const montant = useMontantDocument();
  const [comptes, setComptes] = useState<CompteComptable[]>([]);
  const [compte, setCompte] = useState('');
  const [libelle, setLibelle] = useState(operation.libelle);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    let actif = true;
    // Contreparties possibles : comptes actifs, de détail, ouverts à la saisie manuelle.
    getComptes().then(liste => {
      if (actif) setComptes((liste ?? []).filter(c => c.actif && c.type !== 'en_tete' && c.saisie_manuelle && c.id !== compteBanqueComptable));
    }, () => {});
    return () => { actif = false; };
  }, [compteBanqueComptable]);

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    setOccupe(true);
    setErreur('');
    try {
      onClassee(await classerOperation(operation.id, { compte_comptable_id: Number(compte), libelle: libelle.trim() || null }));
    } catch (err) {
      setErreur(messageErreur(err, tb.error));
    } finally {
      setOccupe(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <form onSubmit={enregistrer} role="dialog" aria-modal="true" aria-labelledby="classer-titre"
        className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-xl max-h-[94vh] overflow-y-auto space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="classer-titre" className="text-lg font-bold text-gray-900 dark:text-white">{ts.classifyTitle}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tb.close}><X size={18} /></button>
        </div>
        <dl className="grid grid-cols-3 gap-3 text-sm dark:text-gray-200">
          <div><dt className="text-xs text-gray-500">{tb.date}</dt><dd>{formatDateDocument(operation.date_operation, language)}</dd></div>
          <div><dt className="text-xs text-gray-500">{tb.type}</dt><dd>{tb.types[operation.montant > 0 ? 'depot' : 'retrait']}</dd></div>
          <div><dt className="text-xs text-gray-500">{tb.amount}</dt><dd className="font-semibold">{montant(Math.abs(operation.montant))}</dd></div>
        </dl>
        <p className="text-xs text-gray-500">{ts.classifyHelp}</p>
        <label className="block text-xs text-gray-500 dark:text-gray-400">{ts.counterpart}
          <select required value={compte} onChange={e => setCompte(e.target.value)} className={classeChamp}>
            <option value="">{tb.chooseLedgerAccount}</option>
            {comptes.map(c => <option key={c.id} value={c.id}>{c.numero} — {c.libelle}</option>)}
          </select>
        </label>
        <label className="block text-xs text-gray-500 dark:text-gray-400">{tb.label}
          <input required maxLength={255} value={libelle} onChange={e => setLibelle(e.target.value)} className={classeChamp} />
        </label>
        {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm dark:border-gray-600 dark:text-gray-200">{tb.cancel}</button>
          <button type="submit" disabled={occupe || !compte} className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700 disabled:opacity-40">{ts.create}</button>
        </div>
      </form>
    </div>
  );
}
