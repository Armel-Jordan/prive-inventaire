import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { CheckCircle2, ChevronDown, ChevronRight, Download, FileSpreadsheet, TriangleAlert } from 'lucide-react';
import {
  getBilan, getCompteResultat, getExercices, exporterBilan, exporterCompteResultat, montant,
  type Bilan, type CompteResultat, type SectionEtat,
} from '@/services/comptabilite';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const aujourdhui = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

/** États financiers : bilan à une date et compte de résultat sur une période, regroupés par en-tête du plan. */
export default function EtatsFinanciersPage() {
  const { t, language } = useLanguage();
  const te = t.accounting.statements;
  const locale = language === 'en' ? 'en-US' : 'fr-FR';

  const [onglet, setOnglet] = useState<'bilan' | 'resultat'>('bilan');
  const [date, setDate] = useState(aujourdhui());
  const [du, setDu] = useState('');
  const [au, setAu] = useState('');
  const [bilan, setBilan] = useState<Bilan | null>(null);
  const [resultat, setResultat] = useState<CompteResultat | null>(null);
  const [erreur, setErreur] = useState('');

  useEffect(() => {
    getExercices().then(({ exercices }) => {
      const exercice = exercices.find(e => e.periodes.some(p => p.courante)) ?? exercices[0];
      if (!exercice) return;
      setDu(d => d || exercice.date_debut);
      setAu(a => a || exercice.date_fin);
    }).catch(() => {});
  }, []);

  useEffect(() => {
    if (onglet !== 'bilan' || !date) return;
    let actif = true;
    getBilan(date).then(b => { if (actif) { setBilan(b); setErreur(''); } }).catch(e => { if (actif) setErreur(messageErreur(e, te.error)); });
    return () => { actif = false; };
  }, [onglet, date, te.error]);

  useEffect(() => {
    if (onglet !== 'resultat' || !du || !au) return;
    let actif = true;
    getCompteResultat(du, au).then(r => { if (actif) { setResultat(r); setErreur(''); } }).catch(e => { if (actif) setErreur(messageErreur(e, te.error)); });
    return () => { actif = false; };
  }, [onglet, du, au, te.error]);

  const exporter = () => (onglet === 'bilan' ? exporterBilan(date) : exporterCompteResultat(du, au)).catch(e => setErreur(messageErreur(e, te.error)));
  const classeOnglet = (actif: boolean) => `px-4 py-2 text-sm font-medium border-b-2 ${actif ? 'border-purple-600 text-purple-700 dark:text-purple-300' : 'border-transparent text-gray-600 hover:text-gray-900 dark:text-gray-300'}`;

  return (
    <div className="p-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><FileSpreadsheet className="text-purple-600" /> {te.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{te.subtitle}</p>
        </div>
        <button onClick={exporter} className="flex items-center gap-2 px-3 py-2 text-sm border rounded-lg text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
          <Download size={16} /> {te.export}
        </button>
      </div>

      <div role="tablist" className="flex gap-2 border-b dark:border-gray-700 mb-4 max-w-6xl">
        <button role="tab" aria-selected={onglet === 'bilan'} onClick={() => setOnglet('bilan')} className={classeOnglet(onglet === 'bilan')}>{te.balanceSheet}</button>
        <button role="tab" aria-selected={onglet === 'resultat'} onClick={() => setOnglet('resultat')} className={classeOnglet(onglet === 'resultat')}>{te.incomeStatement}</button>
      </div>

      {erreur && <p role="status" className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 max-w-6xl">{erreur}</p>}

      {onglet === 'bilan' && (
        <>
          <div className="flex flex-wrap items-end gap-2 mb-4 max-w-6xl">
            <label className="text-xs text-gray-500 dark:text-gray-400 w-44">{te.asOf}
              <input type="date" value={date} onChange={e => setDate(e.target.value)} className={classeChamp} />
            </label>
            {bilan && (
              <span className={`flex items-center gap-1 px-3 py-2 rounded-lg text-sm ${bilan.equilibre ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-700'}`}>
                {bilan.equilibre ? <CheckCircle2 size={16} /> : <TriangleAlert size={16} />} {bilan.equilibre ? te.balanced : te.unbalanced}
              </span>
            )}
          </div>
          {bilan && (
            <div className="grid grid-cols-1 xl:grid-cols-2 gap-4 items-start max-w-6xl">
              <Section titre={te.assets} section={bilan.actif} total={bilan.total_actif} libelleTotal={te.totalAssets} locale={locale} vide={te.empty} />
              <div className="space-y-4">
                <Section titre={te.equity} section={bilan.capitaux_propres} total={bilan.capitaux_propres.total} libelleTotal={te.totalEquity} locale={locale} vide={te.empty}
                  aide={bilan.resultat_non_cloture !== 0 ? te.unclosedHelp : undefined} />
                <Section titre={te.liabilities} section={bilan.passif} total={bilan.passif.total} libelleTotal={te.totalLiabilities} locale={locale} vide={te.empty} />
                <div className="flex justify-between px-4 py-3 rounded-lg bg-white dark:bg-gray-800 shadow-md font-semibold text-gray-900 dark:text-white">
                  <span>{te.totalEquityLiabilities}</span><span>{montant(bilan.total_passif, locale)}</span>
                </div>
              </div>
            </div>
          )}
        </>
      )}

      {onglet === 'resultat' && (
        <>
          <div className="flex flex-wrap items-end gap-2 mb-4 max-w-6xl">
            <label className="text-xs text-gray-500 dark:text-gray-400 w-44">{te.from}
              <input type="date" value={du} onChange={e => setDu(e.target.value)} className={classeChamp} />
            </label>
            <label className="text-xs text-gray-500 dark:text-gray-400 w-44">{te.to}
              <input type="date" value={au} onChange={e => setAu(e.target.value)} className={classeChamp} />
            </label>
          </div>
          {resultat && (
            <div className="grid grid-cols-1 xl:grid-cols-2 gap-4 items-start max-w-6xl">
              <Section titre={te.revenue} section={resultat.produits} total={resultat.produits.total} libelleTotal={te.totalRevenue} locale={locale} vide={te.empty} />
              <div className="space-y-4">
                <Section titre={te.expenses} section={resultat.charges} total={resultat.charges.total} libelleTotal={te.totalExpenses} locale={locale} vide={te.empty} />
                <div className={`flex justify-between px-4 py-3 rounded-lg shadow-md font-semibold ${resultat.resultat >= 0 ? 'bg-green-50 text-green-900' : 'bg-red-50 text-red-800'}`}>
                  <span>{resultat.resultat >= 0 ? te.profit : te.loss}</span><span>{montant(Math.abs(resultat.resultat), locale)}</span>
                </div>
              </div>
            </div>
          )}
        </>
      )}
      <p className="mt-6 text-xs text-gray-500 dark:text-gray-400 max-w-6xl">{te.footnote} <Link to="/balance-comptable" className="text-purple-600 hover:underline dark:text-purple-400">{te.trialBalanceLink}</Link></p>
    </div>
  );
}

interface PropsSection {
  titre: string;
  section: SectionEtat;
  total: number;
  libelleTotal: string;
  locale: string;
  vide: string;
  aide?: string;
}

/** Une section d'état (actif, produits…) : ses groupes, dépliables pour voir les comptes, et son total. */
function Section({ titre, section, total, libelleTotal, locale, vide, aide }: PropsSection) {
  const [deplies, setDeplies] = useState<Set<string>>(new Set());
  const basculer = (cle: string) => setDeplies(d => { const s = new Set(d); if (s.has(cle)) s.delete(cle); else s.add(cle); return s; });

  return (
    <section className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
      <h2 className="px-4 py-3 bg-gray-50 dark:bg-gray-700 text-sm font-semibold uppercase text-gray-700 dark:text-gray-200">{titre}</h2>
      {section.groupes.length === 0 && <p className="px-4 py-3 text-sm text-gray-500">{vide}</p>}
      <ul>
        {section.groupes.map(groupe => {
          const cle = groupe.numero ?? groupe.libelle;
          const deplie = deplies.has(cle);
          return (
            <li key={cle} className="border-t dark:border-gray-700">
              <button onClick={() => groupe.comptes.length && basculer(cle)} aria-expanded={groupe.comptes.length ? deplie : undefined}
                className="w-full flex items-center justify-between gap-3 px-4 py-2 text-sm text-left hover:bg-gray-50 dark:hover:bg-gray-700/50">
                <span className="flex items-center gap-1 text-gray-800 dark:text-gray-200">
                  {groupe.comptes.length > 0 ? (deplie ? <ChevronDown size={14} /> : <ChevronRight size={14} />) : <span className="w-[14px]" />}
                  {groupe.numero && <span className="font-mono text-gray-500 dark:text-gray-400">{groupe.numero}</span>} {groupe.libelle}
                </span>
                <span className="whitespace-nowrap text-gray-900 dark:text-white">{montant(groupe.montant, locale)}</span>
              </button>
              {deplie && (
                <ul className="bg-gray-50/70 dark:bg-gray-900/30">
                  {groupe.comptes.map(compte => (
                    <li key={compte.id} className="flex justify-between gap-3 pl-10 pr-4 py-1 text-xs text-gray-600 dark:text-gray-300">
                      <span><span className="font-mono">{compte.numero}</span> {compte.libelle}</span>
                      <span className="whitespace-nowrap">{montant(compte.montant, locale)}</span>
                    </li>
                  ))}
                </ul>
              )}
            </li>
          );
        })}
      </ul>
      {aide && <p className="px-4 py-2 text-xs text-gray-500 dark:text-gray-400 border-t dark:border-gray-700">{aide}</p>}
      <div className="flex justify-between px-4 py-2 border-t-2 dark:border-gray-600 text-sm font-semibold text-gray-900 dark:text-white">
        <span>{libelleTotal}</span><span>{montant(total, locale)}</span>
      </div>
    </section>
  );
}
