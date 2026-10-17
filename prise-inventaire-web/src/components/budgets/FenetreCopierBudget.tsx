import { useState } from 'react';
import { X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { copierBudget, type ExerciceBudget, type FeuilleBudget } from '@/services/budgets';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

/**
 * Copier le budget d'un autre exercice dans celui-ci, ajusté d'un pourcentage : les périodes se rapprochent par leurs
 * dates (des mois s'additionnent dans leur trimestre, un trimestre se répartit sur ses mois). Sans « remplacer », seules
 * les cellules vides sont remplies.
 */
export default function FenetreCopierBudget({ exercice, exercices, precedentId, onClose, onCopie }: {
  exercice: ExerciceBudget;
  exercices: ExerciceBudget[];
  precedentId: number | null;
  onClose: () => void;
  onCopie: (resultat: FeuilleBudget & { message: string }) => void;
}) {
  const { t } = useLanguage();
  const tb = t.budgets;
  const sources = exercices.filter(e => e.id !== exercice.id);
  const [source, setSource] = useState(String(precedentId ?? sources[0]?.id ?? ''));
  const [pourcentage, setPourcentage] = useState('0');
  const [remplacer, setRemplacer] = useState(false);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  async function copier(e: React.FormEvent) {
    e.preventDefault();
    setOccupe(true);
    setErreur('');
    try {
      onCopie(await copierBudget(exercice.id, Number(source), Number(pourcentage.replace(',', '.')) || 0, remplacer));
    } catch (err) {
      setErreur(messageErreur(err, tb.error));
    } finally {
      setOccupe(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <form onSubmit={copier} role="dialog" aria-modal="true" aria-labelledby="copier-budget-titre" className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-lg space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="copier-budget-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tb.copyTitle.replace('{exercice}', exercice.libelle)}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tb.close}><X size={18} /></button>
        </div>
        <p className="text-xs text-gray-500">{tb.copyHelp}</p>
        <label className="block text-xs text-gray-500 dark:text-gray-400">{tb.source}
          <select required value={source} onChange={e => setSource(e.target.value)} className={classeChamp}>
            {sources.map(e => <option key={e.id} value={e.id}>{e.libelle}</option>)}
          </select>
        </label>
        <label className="block text-xs text-gray-500 dark:text-gray-400">{tb.percent}
          <input type="number" step="0.1" min={-100} max={1000} value={pourcentage} onChange={e => setPourcentage(e.target.value)} className={classeChamp} />
          <span className="block mt-1">{tb.percentHelp}</span>
        </label>
        <label className="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
          <input type="checkbox" checked={remplacer} onChange={e => setRemplacer(e.target.checked)} className="mt-1" />
          <span>{tb.replace}<span className="block text-xs text-gray-500">{tb.replaceHelp}</span></span>
        </label>
        {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm dark:border-gray-600 dark:text-gray-200">{tb.cancel}</button>
          <button type="submit" disabled={occupe || !source} className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-40">{tb.copy}</button>
        </div>
      </form>
    </div>
  );
}
