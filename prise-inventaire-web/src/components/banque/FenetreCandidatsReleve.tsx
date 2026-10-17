import { useEffect, useState } from 'react';
import { Search, X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { formatDateDocument, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import { getCandidatsReleve, relierOperation, type CandidatReleve, type OperationReleve } from '@/services/banque';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const centimes = (valeur: number) => Math.round(valeur * 100);

/**
 * Relier une opération du relevé à des mouvements libres du compte : les plus proches en montant puis en date, dans le
 * sens de l'opération, à 30 jours près ; une recherche porte sur toutes les dates. Plusieurs mouvements peuvent régler
 * une opération (une remise de plusieurs chèques) : le reste à relier se suit à la sélection.
 */
export default function FenetreCandidatsReleve({ operation, onClose, onReliee }: {
  operation: OperationReleve;
  onClose: () => void;
  onReliee: (operation: OperationReleve) => void;
}) {
  const { t, language } = useLanguage();
  const tb = t.bank;
  const ts = t.bank.statements;
  const montant = useMontantDocument();
  const [recherche, setRecherche] = useState('');
  const [candidats, setCandidats] = useState<CandidatReleve[] | null>(null);
  // Gardés d'une recherche à l'autre.
  const [choisis, setChoisis] = useState<CandidatReleve[]>([]);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    let actif = true;
    // Une recherche part après une courte pause de frappe.
    const minuterie = setTimeout(() => {
      getCandidatsReleve(operation.id, recherche.trim() || undefined).then(
        liste => { if (actif) setCandidats(liste); },
        e => { if (actif) setErreur(messageErreur(e, tb.error)); },
      );
    }, recherche ? 300 : 0);
    return () => { actif = false; clearTimeout(minuterie); };
  }, [operation.id, recherche, tb.error]);

  const estChoisi = (id: number) => choisis.some(c => c.id === id);
  const selection = choisis.reduce((s, c) => s + centimes(c.signe), 0);
  const reste = centimes(operation.montant) - centimes(operation.montant_relie) - selection;

  async function relier() {
    setOccupe(true);
    setErreur('');
    try {
      onReliee(await relierOperation(operation.id, choisis.map(c => c.id)));
    } catch (e) {
      setErreur(messageErreur(e, tb.error));
    } finally {
      setOccupe(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="candidats-titre" className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-4xl max-h-[94vh] overflow-y-auto space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="candidats-titre" className="text-lg font-bold text-gray-900 dark:text-white">{ts.candidatesTitle}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tb.close}><X size={18} /></button>
        </div>
        <dl className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm dark:text-gray-200">
          <div><dt className="text-xs text-gray-500">{tb.date}</dt><dd>{formatDateDocument(operation.date_operation, language)}</dd></div>
          <div className="col-span-2 sm:col-span-1"><dt className="text-xs text-gray-500">{ts.operation}</dt><dd>{operation.libelle}{operation.reference ? ` · ${operation.reference}` : ''}</dd></div>
          <div><dt className="text-xs text-gray-500">{tb.amount}</dt><dd className="font-semibold">{montant(operation.montant)}</dd></div>
          <div><dt className="text-xs text-gray-500">{ts.alreadyLinked}</dt><dd>{montant(operation.montant_relie)}</dd></div>
        </dl>
        <p className="text-xs text-gray-500">{ts.candidatesHelp}</p>
        <label className="relative block">
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input value={recherche} onChange={e => setRecherche(e.target.value)} placeholder={ts.searchHelp} aria-label={tb.search} className={`${classeChamp} pl-9`} />
        </label>
        {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}
        {candidats && candidats.length === 0 && <p className="text-sm text-gray-500">{ts.noCandidate}</p>}
        {candidats && candidats.length > 0 && (
          <div className="overflow-x-auto border rounded-lg dark:border-gray-700 max-h-[50vh]">
            <table className="w-full min-w-[720px] text-sm" aria-label={ts.candidatesTitle}>
              <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300 sticky top-0">
                <tr>
                  <th className="px-3 py-2" />
                  <th className="px-3 py-2 text-left">{tb.date}</th>
                  <th className="px-3 py-2 text-left">{tb.entry}</th>
                  <th className="px-3 py-2 text-left">{tb.label}</th>
                  <th className="px-3 py-2 text-left">{tb.origin}</th>
                  <th className="px-3 py-2 text-right">{tb.amount}</th>
                </tr>
              </thead>
              <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                {candidats.map(c => (
                  <tr key={c.id} className={estChoisi(c.id) ? 'bg-blue-50/60 dark:bg-blue-900/20' : ''}>
                    <td className="px-3 py-2 text-center">
                      <input type="checkbox" checked={estChoisi(c.id)} aria-label={`${ts.link} ${c.journal} ${c.numero}`}
                        onChange={e => setChoisis(e.target.checked ? [...choisis, c] : choisis.filter(x => x.id !== c.id))} />
                    </td>
                    <td className="px-3 py-2 whitespace-nowrap">{formatDateDocument(c.date, language)}</td>
                    <td className="px-3 py-2 font-mono text-xs">{c.journal} {c.numero}</td>
                    <td className="px-3 py-2">{c.libelle}{c.reference ? <span className="block text-xs text-gray-500">{c.reference}</span> : null}</td>
                    <td className="px-3 py-2 text-xs">{t.accounting.journal.sources[c.source] ?? c.source}</td>
                    <td className={`px-3 py-2 text-right whitespace-nowrap ${c.signe < 0 ? 'text-red-700 dark:text-red-300' : ''}`}>{montant(c.signe)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <div className="flex flex-wrap items-center justify-between gap-3">
          <p className="text-sm dark:text-gray-200">
            {ts.selected} : <span className="font-semibold">{montant(selection / 100)}</span>
            {' · '}{ts.remainingAfter} : <span className={`font-semibold ${reste === 0 ? 'text-green-700 dark:text-green-300' : 'text-amber-700 dark:text-amber-300'}`}>{montant(reste / 100)}</span>
          </p>
          <div className="flex gap-2">
            <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm dark:border-gray-600 dark:text-gray-200">{tb.cancel}</button>
            <button type="button" disabled={choisis.length === 0 || occupe} onClick={relier} className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-40">{ts.link}</button>
          </div>
        </div>
      </div>
    </div>
  );
}
