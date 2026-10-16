import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Download, ScrollText } from 'lucide-react';
import { getComptes, getExercices, getGrandLivre, exporterGrandLivre, montant, type CompteComptable, type GrandLivre } from '@/services/comptabilite';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

/** Grand livre d'un compte (un en-tête cumule ses sous-comptes) : solde d'ouverture, écritures, solde progressif. */
export default function GrandLivrePage() {
  const { t, language } = useLanguage();
  const tl = t.accounting.ledger;
  const locale = language === 'en' ? 'en-US' : 'fr-FR';
  const [parametres, setParametres] = useSearchParams();

  const [comptes, setComptes] = useState<CompteComptable[]>([]);
  const [compteId, setCompteId] = useState(parametres.get('compte') ?? '');
  const [du, setDu] = useState(parametres.get('du') ?? '');
  const [au, setAu] = useState(parametres.get('au') ?? '');
  const [recherche, setRecherche] = useState('');
  const [livre, setLivre] = useState<GrandLivre | null>(null);
  const [erreur, setErreur] = useState('');

  useEffect(() => {
    getComptes().then(setComptes).catch(e => setErreur(messageErreur(e, tl.error)));
    getExercices().then(({ exercices }) => {
      const exercice = exercices.find(e => e.periodes.some(p => p.courante)) ?? exercices[0];
      if (!exercice) return;
      setDu(d => d || exercice.date_debut);
      setAu(a => a || exercice.date_fin);
    }).catch(() => {});
  }, [tl.error]);

  useEffect(() => {
    if (!compteId || !du || !au) return;
    let actif = true;
    setParametres({ compte: compteId, du, au }, { replace: true });
    const delai = setTimeout(() => {
      getGrandLivre(Number(compteId), du, au, recherche.trim() || undefined)
        .then(l => { if (actif) { setLivre(l); setErreur(''); } })
        .catch(e => { if (actif) setErreur(messageErreur(e, tl.error)); });
    }, 250);
    return () => { actif = false; clearTimeout(delai); };
  }, [compteId, du, au, recherche, setParametres, tl.error]);

  const date = (iso: string) => { const [a, m, j] = iso.split('-').map(Number); return new Date(a, m - 1, j).toLocaleDateString(locale); };
  const solde = (valeur: number) => (valeur === 0 ? montant(0, locale) : `${montant(Math.abs(valeur), locale)} ${valeur > 0 ? tl.debitShort : tl.creditShort}`);

  return (
    <div className="p-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><ScrollText className="text-purple-600" /> {tl.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tl.subtitle}</p>
        </div>
        {livre && (
          <button onClick={() => exporterGrandLivre(livre.compte.id, livre.compte.numero, livre.du, livre.au).catch(e => setErreur(messageErreur(e, tl.error)))}
            className="flex items-center gap-2 px-3 py-2 text-sm border rounded-lg text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
            <Download size={16} /> {tl.export}
          </button>
        )}
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2 mb-4 max-w-6xl">
        <label className="text-xs text-gray-500 dark:text-gray-400 lg:col-span-2">{tl.account}
          <select value={compteId} onChange={e => setCompteId(e.target.value)} className={classeChamp}>
            <option value="">{tl.chooseAccount}</option>
            {comptes.map(c => <option key={c.id} value={c.id}>{c.numero} — {c.libelle}{c.type === 'en_tete' ? ` (${tl.withSubAccounts})` : ''}</option>)}
          </select>
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400">{tl.from}
          <input type="date" value={du} onChange={e => setDu(e.target.value)} className={classeChamp} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400">{tl.to}
          <input type="date" value={au} onChange={e => setAu(e.target.value)} className={classeChamp} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400 lg:col-span-2">{tl.search}
          <input value={recherche} onChange={e => setRecherche(e.target.value)} placeholder={tl.searchHelp} className={classeChamp} />
        </label>
      </div>

      {erreur && <p role="status" className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 max-w-6xl">{erreur}</p>}
      {!compteId && <p className="text-sm text-gray-500 dark:text-gray-400">{tl.chooseHelp}</p>}

      {livre && compteId && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto max-w-6xl">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 dark:bg-gray-700">
              <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
                <th className="px-4 py-3 font-medium">{tl.date}</th>
                <th className="px-4 py-3 font-medium">{tl.entry}</th>
                {livre.compte.type === 'en_tete' && <th className="px-4 py-3 font-medium">{tl.account}</th>}
                <th className="px-4 py-3 font-medium">{tl.label}</th>
                <th className="px-4 py-3 font-medium text-right">{tl.debit}</th>
                <th className="px-4 py-3 font-medium text-right">{tl.credit}</th>
                <th className="px-4 py-3 font-medium text-right">{tl.balance}</th>
              </tr>
            </thead>
            <tbody>
              <tr className="border-t dark:border-gray-700 bg-gray-50/60 dark:bg-gray-900/30 font-medium">
                <td className="px-4 py-2 whitespace-nowrap text-gray-700 dark:text-gray-300">{date(livre.du)}</td>
                <td colSpan={livre.compte.type === 'en_tete' ? 4 : 3} className="px-4 py-2 text-gray-800 dark:text-gray-200">{tl.opening}</td>
                <td />
                <td className="px-4 py-2 text-right whitespace-nowrap text-gray-900 dark:text-white">{solde(livre.solde_ouverture)}</td>
              </tr>
              {livre.lignes.length === 0 && <tr><td colSpan={7} className="px-4 py-6 text-center text-gray-500">{tl.empty}</td></tr>}
              {livre.lignes.map((ligne, index) => (
                <tr key={`${ligne.ecriture_id}-${index}`} className="border-t dark:border-gray-700">
                  <td className="px-4 py-2 whitespace-nowrap text-gray-700 dark:text-gray-300">{date(ligne.date)}</td>
                  <td className="px-4 py-2 whitespace-nowrap font-mono text-gray-900 dark:text-white">{ligne.numero}</td>
                  {livre.compte.type === 'en_tete' && <td className="px-4 py-2 font-mono text-gray-700 dark:text-gray-300">{ligne.compte}</td>}
                  <td className="px-4 py-2 text-gray-800 dark:text-gray-200">{ligne.libelle}{ligne.reference && <span className="ml-2 text-xs text-gray-500">{tl.ref} {ligne.reference}</span>}</td>
                  <td className="px-4 py-2 text-right whitespace-nowrap text-gray-900 dark:text-white">{ligne.debit ? montant(ligne.debit, locale) : ''}</td>
                  <td className="px-4 py-2 text-right whitespace-nowrap text-gray-900 dark:text-white">{ligne.credit ? montant(ligne.credit, locale) : ''}</td>
                  <td className="px-4 py-2 text-right whitespace-nowrap text-gray-700 dark:text-gray-300">{solde(ligne.solde)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr className="border-t-2 dark:border-gray-600 font-semibold text-gray-900 dark:text-white">
                <td colSpan={livre.compte.type === 'en_tete' ? 4 : 3} className="px-4 py-2">{tl.totals}</td>
                <td className="px-4 py-2 text-right whitespace-nowrap">{montant(livre.total_debit, locale)}</td>
                <td className="px-4 py-2 text-right whitespace-nowrap">{montant(livre.total_credit, locale)}</td>
                <td className="px-4 py-2 text-right whitespace-nowrap">{livre.solde_final !== null ? solde(livre.solde_final) : ''}</td>
              </tr>
            </tfoot>
          </table>
          {livre.tronque && <p className="px-4 py-2 text-xs text-amber-800 bg-amber-50">{tl.truncated}</p>}
        </div>
      )}
    </div>
  );
}
