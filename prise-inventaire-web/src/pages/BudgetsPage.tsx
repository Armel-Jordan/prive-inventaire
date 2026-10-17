import { useEffect, useState } from 'react';
import { PiggyBank } from 'lucide-react';
import GrilleBudget from '@/components/budgets/GrilleBudget';
import OngletBudgetEtReel from '@/components/budgets/OngletBudgetEtReel';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { getFeuilleBudget, type ExerciceBudget, type FeuilleBudget } from '@/services/budgets';

const classeChamp = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';
type Onglet = 'saisie' | 'comparaison';

/**
 * Budgets (Finance → Budgets) : la feuille du budget d'un exercice, saisie cellule par cellule, et le budget comparé au
 * réel du journal (avec le droit de voir les états comptables).
 */
export default function BudgetsPage() {
  const { t } = useLanguage();
  const tb = t.budgets;
  const { peut } = usePermissions();
  const voirReel = peut('comptabilite', 'etats');
  const [onglet, setOnglet] = useState<Onglet>('saisie');
  const [exerciceId, setExerciceId] = useState<number | undefined>(undefined);
  const [exercices, setExercices] = useState<ExerciceBudget[]>([]);
  const [feuille, setFeuille] = useState<FeuilleBudget | null>(null);
  const [erreur, setErreur] = useState('');

  // Rechargée au changement d'exercice, et à la demande (comptes du budget modifiés).
  const [version, setVersion] = useState(0);
  useEffect(() => {
    let actif = true;
    getFeuilleBudget(exerciceId).then(reponse => {
      if (!actif) return;
      setExercices(reponse.exercices);
      setFeuille(reponse);
      setErreur('');
    }, e => { if (actif) setErreur(messageErreur(e, tb.error)); });
    return () => { actif = false; };
  }, [exerciceId, version, tb.error]);

  const exercice = feuille?.exercice ?? null;

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><PiggyBank size={24} /> {tb.title}</h1>
        <p className="text-sm text-gray-500 max-w-3xl">{tb.subtitle}</p>
      </div>
      <div className="flex flex-wrap items-end gap-3">
        <label className="text-xs text-gray-500 dark:text-gray-400">{tb.year}
          <select value={exercice?.id ?? ''} onChange={e => setExerciceId(Number(e.target.value))} className={`${classeChamp} block`}>
            {exercices.length === 0 && <option value="">{tb.noYear}</option>}
            {exercices.map(e => <option key={e.id} value={e.id}>{e.libelle}{e.statut === 'cloture' ? ` (${tb.closed})` : ''}</option>)}
          </select>
        </label>
        <div role="tablist" className="flex gap-1 border-b dark:border-gray-700 ml-auto">
          {(['saisie', 'comparaison'] as Onglet[]).filter(o => o === 'saisie' || voirReel).map(o => (
            <button key={o} role="tab" type="button" aria-selected={onglet === o} onClick={() => setOnglet(o)}
              className={`px-4 py-2 text-sm border-b-2 -mb-px ${onglet === o ? 'border-blue-600 text-blue-700 dark:text-blue-300 font-medium' : 'border-transparent text-gray-600 dark:text-gray-300 hover:text-gray-900'}`}>
              {tb.tabs[o]}
            </button>
          ))}
        </div>
      </div>
      {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}
      {feuille && !exercice && <p className="text-sm text-gray-500">{tb.noYearHelp}</p>}

      {exercice && feuille && onglet === 'saisie' && (
        <GrilleBudget feuille={{ ...feuille, exercice }} exercices={exercices} peutSaisir={peut('budgets', 'saisir')} onFeuille={setFeuille} onRecharger={() => setVersion(v => v + 1)} />
      )}
      {exercice && feuille && onglet === 'comparaison' && voirReel && <OngletBudgetEtReel exercice={exercice} periodes={feuille.periodes ?? []} />}
    </div>
  );
}
