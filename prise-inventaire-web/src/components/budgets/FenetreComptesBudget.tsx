import { useEffect, useMemo, useState } from 'react';
import { Search, X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { marquerComptesBudget } from '@/services/budgets';
import { getComptes, type CompteComptable, type NatureCompte } from '@/services/comptabilite';

const classeChamp = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';
const NATURES: NatureCompte[] = ['produit', 'charge', 'actif', 'passif', 'capitaux_propres'];

/**
 * Comptes du budget : les comptes de détail à budgéter, cochés ou décochés (produits et charges d'un geste). Un compte
 * retiré garde ses montants déjà saisis, visibles dans la feuille.
 */
export default function FenetreComptesBudget({ onClose, onEnregistre }: { onClose: () => void; onEnregistre: (message: string) => void }) {
  const { t } = useLanguage();
  const tb = t.budgets;
  const [comptes, setComptes] = useState<CompteComptable[] | null>(null);
  const [coches, setCoches] = useState<Set<number>>(new Set());
  const [recherche, setRecherche] = useState('');
  const [nature, setNature] = useState<NatureCompte | ''>('');
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    let actif = true;
    getComptes().then(liste => {
      if (!actif) return;
      // Comptes de détail, actifs ou déjà budgétés.
      const detail = (liste ?? []).filter(c => c.type !== 'en_tete' && (c.actif || c.budgetable));
      setComptes(detail);
      setCoches(new Set(detail.filter(c => c.budgetable).map(c => c.id)));
    }, e => { if (actif) setErreur(messageErreur(e, tb.error)); });
    return () => { actif = false; };
  }, [tb.error]);

  const visibles = useMemo(() => {
    const texte = recherche.trim().toLowerCase();
    return (comptes ?? []).filter(c => (!nature || c.nature === nature) && (!texte || `${c.numero} ${c.libelle}`.toLowerCase().includes(texte)));
  }, [comptes, recherche, nature]);

  const basculer = (ids: number[], coche: boolean) => setCoches(actuelles => {
    const suite = new Set(actuelles);
    ids.forEach(id => (coche ? suite.add(id) : suite.delete(id)));
    return suite;
  });

  async function enregistrer() {
    const initiales = new Set((comptes ?? []).filter(c => c.budgetable).map(c => c.id));
    const ajouts = [...coches].filter(id => !initiales.has(id));
    const retraits = [...initiales].filter(id => !coches.has(id));
    setOccupe(true);
    setErreur('');
    try {
      const messages: string[] = [];
      if (ajouts.length) messages.push((await marquerComptesBudget(ajouts, true)).message);
      if (retraits.length) messages.push((await marquerComptesBudget(retraits, false)).message);
      onEnregistre(messages.join(' ') || tb.accountsUnchanged);
    } catch (e) {
      setErreur(messageErreur(e, tb.error));
    } finally {
      setOccupe(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="comptes-budget-titre" className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-3xl max-h-[94vh] flex flex-col gap-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="comptes-budget-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tb.accountsTitle}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tb.close}><X size={18} /></button>
        </div>
        <p className="text-xs text-gray-500">{tb.accountsHelp}</p>
        <div className="flex flex-wrap items-center gap-2">
          <label className="relative flex-1 min-w-[200px]">
            <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <input value={recherche} onChange={e => setRecherche(e.target.value)} placeholder={tb.searchHelp} aria-label={tb.search} className={`${classeChamp} w-full pl-9`} />
          </label>
          <select value={nature} onChange={e => setNature(e.target.value as NatureCompte | '')} aria-label={tb.nature} className={classeChamp}>
            <option value="">{tb.allNatures}</option>
            {NATURES.map(n => <option key={n} value={n}>{t.accounting.natures[n]}</option>)}
          </select>
          <button type="button" onClick={() => basculer(visibles.filter(c => c.nature === 'produit' || c.nature === 'charge').map(c => c.id), true)}
            className="px-3 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">{tb.incomeAndExpenses}</button>
          <button type="button" onClick={() => basculer(visibles.map(c => c.id), false)}
            className="px-3 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">{tb.noneVisible}</button>
        </div>
        {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}
        <div className="flex-1 overflow-y-auto border rounded-lg dark:border-gray-700 min-h-[200px]">
          {comptes === null ? <p className="p-3 text-sm text-gray-500">{tb.loading}</p> : visibles.length === 0 ? <p className="p-3 text-sm text-gray-500">{tb.noAccountMatch}</p> : (
            <ul className="divide-y dark:divide-gray-700">
              {visibles.map(c => (
                <li key={c.id}>
                  <label className="flex items-center gap-3 px-3 py-2 text-sm cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40 dark:text-gray-200">
                    <input type="checkbox" checked={coches.has(c.id)} onChange={e => basculer([c.id], e.target.checked)} />
                    <span className="font-mono text-xs w-16">{c.numero}</span>
                    <span className="flex-1">{c.libelle}</span>
                    <span className="text-xs text-gray-500">{t.accounting.natures[c.nature]}</span>
                  </label>
                </li>
              ))}
            </ul>
          )}
        </div>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span className="text-sm text-gray-600 dark:text-gray-300">{tb.accountsChecked.replace('{n}', String(coches.size))}</span>
          <div className="flex gap-2">
            <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm dark:border-gray-600 dark:text-gray-200">{tb.cancel}</button>
            <button type="button" disabled={occupe || comptes === null} onClick={enregistrer} className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-40">{tb.save}</button>
          </div>
        </div>
      </div>
    </div>
  );
}
