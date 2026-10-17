import { useEffect, useState } from 'react';
import { Download } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { aujourdhui, formatDateDocument, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import {
  getComparaisonBudget, telechargerComparaisonBudget, type ComparaisonBudget, type ExerciceBudget, type LigneComparaison, type PeriodeBudget, type TotalComparaison,
} from '@/services/budgets';

const classeChamp = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

/** Couleur d'un écart : vert s'il est favorable, rouge sinon, neutre sans sens (actif, passif). */
const couleur = (favorable: boolean | null) => (favorable === null ? '' : favorable ? 'text-green-700 dark:text-green-300' : 'text-red-700 dark:text-red-300');

/**
 * Budget et réel d'un exercice, de la première à la dernière période choisies (par défaut jusqu'à la période du jour) :
 * par compte, le budget, le réel du journal (hors clôture), l'écart et le taux de réalisation ; produits, charges (dont
 * hors budget) et résultat ; export CSV.
 */
export default function OngletBudgetEtReel({ exercice, periodes }: { exercice: ExerciceBudget; periodes: PeriodeBudget[] }) {
  const { t, language } = useLanguage();
  const tb = t.budgets;
  const montant = useMontantDocument();
  const jour = aujourdhui();
  const [choix, setChoix] = useState<{ exercice: number; du: number; au: number } | null>(null);
  // Par défaut : de la première période à celle du jour (sinon la dernière).
  const parDefaut = { exercice: exercice.id, du: periodes[0]?.id ?? 0, au: (periodes.find(p => p.date_debut <= jour && p.date_fin >= jour) ?? periodes[periodes.length - 1])?.id ?? 0 };
  const selection = choix?.exercice === exercice.id ? choix : parDefaut;
  const [donnees, setDonnees] = useState<ComparaisonBudget | null>(null);
  const [erreur, setErreur] = useState('');

  useEffect(() => {
    if (!selection.du || !selection.au) return;
    let actif = true;
    getComparaisonBudget(exercice.id, selection.du, selection.au).then(
      d => { if (actif) { setDonnees(d); setErreur(''); } },
      e => { if (actif) { setDonnees(null); setErreur(messageErreur(e, tb.error)); } },
    );
    return () => { actif = false; };
  }, [exercice.id, selection.du, selection.au, tb.error]);

  const lignesDe = (nature: string) => (donnees?.lignes ?? []).filter(l => l.nature === nature);
  const autres = (donnees?.lignes ?? []).filter(l => l.nature !== 'produit' && l.nature !== 'charge');

  const ligne = (l: LigneComparaison) => (
    <tr key={l.id}>
      <td className="px-3 py-2"><span className="font-mono text-xs">{l.numero}</span> {l.libelle}</td>
      <td className="px-3 py-2 text-right whitespace-nowrap">{montant(l.budget)}</td>
      <td className="px-3 py-2 text-right whitespace-nowrap">{montant(l.reel)}</td>
      <td className={`px-3 py-2 text-right whitespace-nowrap ${couleur(l.favorable)}`}>{montant(l.ecart)}</td>
      <td className="px-3 py-2 w-40">
        {l.taux === null ? <span className="text-xs text-gray-400">—</span> : (
          <div className="flex items-center gap-2">
            <div className="flex-1 h-1.5 rounded bg-gray-200 dark:bg-gray-600 overflow-hidden">
              <div className={`h-full ${l.favorable === false ? 'bg-red-500' : 'bg-green-500'}`} style={{ width: `${Math.max(0, Math.min(100, l.taux))}%` }} />
            </div>
            <span className="text-xs w-14 text-right">{l.taux.toLocaleString(language === 'en' ? 'en-US' : 'fr-FR')} %</span>
          </div>
        )}
      </td>
    </tr>
  );
  const total = (libelle: string, valeurs: TotalComparaison, favorable: boolean | null, fort = false) => (
    <tr className={fort ? 'bg-gray-50 dark:bg-gray-700/40 font-semibold' : 'font-medium'}>
      <td className="px-3 py-2">{libelle}</td>
      <td className="px-3 py-2 text-right whitespace-nowrap">{montant(valeurs.budget)}</td>
      <td className="px-3 py-2 text-right whitespace-nowrap">{montant(valeurs.reel)}</td>
      <td className={`px-3 py-2 text-right whitespace-nowrap ${couleur(favorable)}`}>{montant(valeurs.ecart)}</td>
      <td />
    </tr>
  );
  const horsBudget = (libelle: string, valeur: number) => valeur !== 0 && (
    <tr className="text-gray-500 italic">
      <td className="px-3 py-2">{libelle}</td>
      <td className="px-3 py-2" />
      <td className="px-3 py-2 text-right whitespace-nowrap">{montant(valeur)}</td>
      <td colSpan={2} />
    </tr>
  );

  return (
    <div className="space-y-3">
      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4 flex flex-wrap items-end gap-3">
        <label className="text-xs text-gray-500 dark:text-gray-400">{tb.fromPeriod}
          <select value={selection.du} onChange={e => setChoix({ ...selection, du: Number(e.target.value) })} className={`${classeChamp} block`}>
            {periodes.map(p => <option key={p.id} value={p.id}>{tb.periodN.replace('{n}', String(p.numero))} — {formatDateDocument(p.date_debut, language)}</option>)}
          </select>
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400">{tb.toPeriod}
          <select value={selection.au} onChange={e => setChoix({ ...selection, au: Number(e.target.value) })} className={`${classeChamp} block`}>
            {periodes.map(p => <option key={p.id} value={p.id}>{tb.periodN.replace('{n}', String(p.numero))} — {formatDateDocument(p.date_fin, language)}</option>)}
          </select>
        </label>
        {donnees && (
          <button type="button" onClick={() => telechargerComparaisonBudget(exercice.id, selection.du, selection.au, `budget-et-reel-${donnees.du}-${donnees.au}.csv`).catch(e => setErreur(messageErreur(e, tb.error)))}
            className="ml-auto flex items-center gap-2 px-3 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">
            <Download size={16} /> {tb.csv}
          </button>
        )}
      </div>
      <p className="text-xs text-gray-500 max-w-4xl">{tb.comparisonHelp}</p>
      {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}
      {donnees && (
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-x-auto">
          <table className="w-full min-w-[760px] text-sm" aria-label={tb.tabs.comparaison}>
            <caption className="text-left px-4 py-2 text-xs text-gray-500">{tb.periodRange.replace('{du}', formatDateDocument(donnees.du, language)).replace('{au}', formatDateDocument(donnees.au, language))}</caption>
            <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
              <tr>
                <th className="px-3 py-2 text-left">{tb.account}</th>
                <th className="px-3 py-2 text-right">{tb.budget}</th>
                <th className="px-3 py-2 text-right">{tb.actual}</th>
                <th className="px-3 py-2 text-right">{tb.gap}</th>
                <th className="px-3 py-2 text-left">{tb.achieved}</th>
              </tr>
            </thead>
            <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
              {donnees.lignes.length === 0 && <tr><td colSpan={5} className="px-3 py-3 text-gray-500">{tb.noLine}</td></tr>}
              {lignesDe('produit').map(ligne)}
              {horsBudget(tb.incomeOutside, donnees.hors_budget.produits)}
              {total(tb.income, donnees.totaux.produits, donnees.totaux.produits.ecart >= 0)}
              {lignesDe('charge').map(ligne)}
              {horsBudget(tb.expensesOutside, donnees.hors_budget.charges)}
              {total(tb.expenses, donnees.totaux.charges, donnees.totaux.charges.ecart <= 0)}
              {autres.length > 0 && <tr><td colSpan={5} className="px-3 pt-4 pb-1 text-xs font-medium text-gray-500">{tb.others}</td></tr>}
              {autres.map(ligne)}
              {total(tb.result, donnees.totaux.resultat, donnees.totaux.resultat.ecart >= 0, true)}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
