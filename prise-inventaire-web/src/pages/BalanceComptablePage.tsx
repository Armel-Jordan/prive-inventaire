import { Fragment, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { CheckCircle2, Download, Scale, TriangleAlert } from 'lucide-react';
import { getBalance, getExercices, exporterBalance, montant, type Balance, type LigneBalance } from '@/services/comptabilite';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const COLONNES = ['ouverture_debit', 'ouverture_credit', 'mouvement_debit', 'mouvement_credit', 'solde_debit', 'solde_credit'] as const;

/** Balance des comptes : soldes d'ouverture, mouvements et soldes de la période, par classe, avec contrôle d'équilibre. */
export default function BalanceComptablePage() {
  const { t, language } = useLanguage();
  const tb = t.accounting.trialBalance;
  const locale = language === 'en' ? 'en-US' : 'fr-FR';

  const [du, setDu] = useState('');
  const [au, setAu] = useState('');
  const [balance, setBalance] = useState<Balance | null>(null);
  const [erreur, setErreur] = useState('');

  useEffect(() => {
    getExercices().then(({ exercices }) => {
      const exercice = exercices.find(e => e.periodes.some(p => p.courante)) ?? exercices[0];
      setDu(d => d || exercice?.date_debut || '');
      setAu(a => a || exercice?.date_fin || '');
      if (!exercice) getBalance().then(b => { setBalance(b); setDu(b.du); setAu(b.au); }).catch(e => setErreur(messageErreur(e, tb.error)));
    }).catch(e => setErreur(messageErreur(e, tb.error)));
  }, [tb.error]);

  useEffect(() => {
    if (!du || !au) return;
    let actif = true;
    getBalance(du, au).then(b => { if (actif) { setBalance(b); setErreur(''); } }).catch(e => { if (actif) setErreur(messageErreur(e, tb.error)); });
    return () => { actif = false; };
  }, [du, au, tb.error]);

  // Comptes regroupés par classe (premier caractère du numéro), avec sous-totaux.
  const classes = useMemo(() => {
    const groupes = new Map<string, LigneBalance[]>();
    for (const ligne of balance?.comptes ?? []) groupes.set(ligne.numero[0], [...(groupes.get(ligne.numero[0]) ?? []), ligne]);
    return [...groupes.entries()].map(([classe, lignes]) => ({
      classe,
      lignes,
      totaux: Object.fromEntries(COLONNES.map(c => [c, lignes.reduce((s, l) => s + l[c], 0)])) as Record<(typeof COLONNES)[number], number>,
    }));
  }, [balance]);

  const cellule = (valeur: number) => (valeur ? montant(valeur, locale) : '');

  return (
    <div className="p-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Scale className="text-purple-600" /> {tb.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tb.subtitle}</p>
        </div>
        {balance && (
          <button onClick={() => exporterBalance(balance.du, balance.au).catch(e => setErreur(messageErreur(e, tb.error)))}
            className="flex items-center gap-2 px-3 py-2 text-sm border rounded-lg text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
            <Download size={16} /> {tb.export}
          </button>
        )}
      </div>

      <div className="flex flex-wrap items-end gap-2 mb-4 max-w-6xl">
        <label className="text-xs text-gray-500 dark:text-gray-400 w-44">{tb.from}
          <input type="date" value={du} onChange={e => setDu(e.target.value)} className={classeChamp} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400 w-44">{tb.to}
          <input type="date" value={au} onChange={e => setAu(e.target.value)} className={classeChamp} />
        </label>
        {balance && (
          <span className={`flex items-center gap-1 px-3 py-2 rounded-lg text-sm ${balance.equilibree ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-700'}`}>
            {balance.equilibree ? <CheckCircle2 size={16} /> : <TriangleAlert size={16} />} {balance.equilibree ? tb.balanced : tb.unbalanced}
          </span>
        )}
      </div>

      {erreur && <p role="status" className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 max-w-6xl">{erreur}</p>}

      {balance && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto max-w-6xl">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 dark:bg-gray-700 text-xs uppercase text-gray-500 dark:text-gray-300">
              <tr>
                <th rowSpan={2} className="px-3 py-2 text-left font-medium">{tb.account}</th>
                <th colSpan={2} className="px-3 py-2 font-medium border-l dark:border-gray-600">{tb.opening}</th>
                <th colSpan={2} className="px-3 py-2 font-medium border-l dark:border-gray-600">{tb.movements}</th>
                <th colSpan={2} className="px-3 py-2 font-medium border-l dark:border-gray-600">{tb.closing}</th>
              </tr>
              <tr>
                {COLONNES.map((c, i) => <th key={c} className={`px-3 py-1 text-right font-medium ${i % 2 === 0 ? 'border-l dark:border-gray-600' : ''}`}>{i % 2 === 0 ? tb.debit : tb.credit}</th>)}
              </tr>
            </thead>
            <tbody>
              {balance.comptes.length === 0 && <tr><td colSpan={7} className="px-4 py-6 text-center text-gray-500">{tb.empty}</td></tr>}
              {classes.map(({ classe, lignes, totaux }) => (
                <Fragment key={classe}>
                  {lignes.map(ligne => (
                    <tr key={ligne.compte_id} className="border-t dark:border-gray-700">
                      <td className="px-3 py-1.5">
                        <Link to={`/grand-livre?compte=${ligne.compte_id}&du=${balance.du}&au=${balance.au}`} className="hover:underline">
                          <span className="font-mono text-gray-900 dark:text-white">{ligne.numero}</span> <span className="text-gray-700 dark:text-gray-300">{ligne.libelle}</span>
                        </Link>
                      </td>
                      {COLONNES.map((c, i) => <td key={c} className={`px-3 py-1.5 text-right whitespace-nowrap text-gray-900 dark:text-white ${i % 2 === 0 ? 'border-l dark:border-gray-700' : ''}`}>{cellule(ligne[c])}</td>)}
                    </tr>
                  ))}
                  <tr className="border-t bg-gray-50/70 dark:bg-gray-900/30 dark:border-gray-700 font-medium text-gray-800 dark:text-gray-200">
                    <td className="px-3 py-1.5">{tb.classTotal.replace('{classe}', classe)}</td>
                    {COLONNES.map((c, i) => <td key={c} className={`px-3 py-1.5 text-right whitespace-nowrap ${i % 2 === 0 ? 'border-l dark:border-gray-700' : ''}`}>{cellule(totaux[c])}</td>)}
                  </tr>
                </Fragment>
              ))}
            </tbody>
            <tfoot>
              <tr className="border-t-2 dark:border-gray-600 font-semibold text-gray-900 dark:text-white">
                <td className="px-3 py-2">{tb.totals}</td>
                {COLONNES.map((c, i) => <td key={c} className={`px-3 py-2 text-right whitespace-nowrap ${i % 2 === 0 ? 'border-l dark:border-gray-700' : ''}`}>{montant(balance.totaux[c], locale)}</td>)}
              </tr>
            </tfoot>
          </table>
        </div>
      )}
    </div>
  );
}
