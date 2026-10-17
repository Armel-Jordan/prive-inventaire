import { useEffect, useState } from 'react';
import { BadgePercent, Download, FileDown } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { aujourdhui, formatDateDocument, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import { ouvrirPdf } from '@/services/api';
import {
  cheminPdfRapportTaxes, getRapportTaxes, NIVEAUX_RAPPORT_TAXES, telechargerRapportTaxes, type LigneRapportTaxes, type MontantsTaxes, type NiveauRapportTaxes, type RapportTaxes,
} from '@/services/rapportTaxes';

const classeChamp = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';
const classeBouton = 'flex items-center gap-1 px-3 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700';

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

/** Périodes usuelles d'une déclaration : le mois précédent, le mois en cours, le trimestre précédent. */
function periodeType(type: 'mois_precedent' | 'mois' | 'trimestre_precedent'): { du: string; au: string } {
  const jour = new Date(`${aujourdhui()}T12:00:00`);
  const annee = jour.getFullYear();
  const mois = jour.getMonth();
  if (type === 'mois') return { du: iso(new Date(annee, mois, 1)), au: aujourdhui() };
  if (type === 'mois_precedent') return { du: iso(new Date(annee, mois - 1, 1)), au: iso(new Date(annee, mois, 0)) };
  const debut = Math.floor(mois / 3) * 3 - 3;
  return { du: iso(new Date(annee, debut, 1)), au: iso(new Date(annee, debut + 3, 0)) };
}

/**
 * Rapport des taxes (Finance → Rapport des taxes) : pour une période, les taxes collectées sur les ventes (moins les
 * notes de crédit) et déductibles sur les achats, taxe par taxe ; en détail, par jour ou pour la période ; le sommaire
 * par taxe (à reverser ou à récupérer) et les comptes de taxes du journal pour les rapprocher ; CSV et PDF.
 */
export default function RapportTaxesPage() {
  const { t, language } = useLanguage();
  const tt = t.taxReport;
  const montant = useMontantDocument();
  const nombre = new Intl.NumberFormat(language === 'en' ? 'en-US' : 'fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const cellule = (valeur: number | undefined) => (valeur ? nombre.format(valeur) : '');
  const [periode, setPeriode] = useState(() => periodeType('mois_precedent'));
  const [niveau, setNiveau] = useState<NiveauRapportTaxes>('detail');
  const [rapport, setRapport] = useState<RapportTaxes | null>(null);
  const [erreur, setErreur] = useState('');

  useEffect(() => {
    if (!periode.du || !periode.au) return;
    let actif = true;
    getRapportTaxes(periode.du, periode.au, niveau).then(
      r => { if (actif) { setRapport(r); setErreur(''); } },
      e => { if (actif) { setRapport(null); setErreur(messageErreur(e, tt.error)); } },
    );
    return () => { actif = false; };
  }, [periode, niveau, tt.error]);

  const taxes = rapport?.taxes ?? [];
  const detail = rapport?.niveau === 'detail';
  const parJour = rapport?.niveau === 'jour';
  const colonnesDebut = detail ? 4 : parJour ? 1 : 0;
  const montants = (ligne: MontantsTaxes) => (
    <>
      <td className="px-3 py-2 text-right whitespace-nowrap">{cellule(ligne.ventes_ht)}</td>
      <td className="px-3 py-2 text-right whitespace-nowrap">{cellule(ligne.achats_ht)}</td>
      {taxes.flatMap(taxe => [
        <td key={`${taxe.cle}-c`} className="px-3 py-2 text-right whitespace-nowrap border-l dark:border-gray-700">{cellule(ligne.collectee[taxe.cle])}</td>,
        <td key={`${taxe.cle}-d`} className="px-3 py-2 text-right whitespace-nowrap">{cellule(ligne.deductible[taxe.cle])}</td>,
      ])}
    </>
  );
  const debutLigne = (ligne: LigneRapportTaxes) => (
    <>
      {(detail || parJour) && <td className="px-3 py-2 whitespace-nowrap">{ligne.date ? formatDateDocument(ligne.date, language) : ''}</td>}
      {detail && (
        <>
          <td className="px-3 py-2 whitespace-nowrap">{ligne.type ? tt.types[ligne.type] : ''}</td>
          <td className="px-3 py-2 font-mono text-xs whitespace-nowrap">{ligne.numero}{ligne.reference ? <span className="block text-gray-500">{ligne.reference}</span> : null}</td>
          <td className="px-3 py-2">{ligne.tiers}</td>
        </>
      )}
    </>
  );

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><BadgePercent size={24} /> {tt.title}</h1>
        <p className="text-sm text-gray-500 max-w-3xl">{tt.subtitle}</p>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4 flex flex-wrap items-end gap-3">
        <label className="text-xs text-gray-500 dark:text-gray-400">{tt.from}
          <input type="date" value={periode.du} onChange={e => setPeriode({ ...periode, du: e.target.value })} className={`${classeChamp} block`} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400">{tt.to}
          <input type="date" value={periode.au} onChange={e => setPeriode({ ...periode, au: e.target.value })} className={`${classeChamp} block`} />
        </label>
        <div className="flex flex-wrap gap-1">
          {(['mois_precedent', 'mois', 'trimestre_precedent'] as const).map(p => (
            <button key={p} type="button" onClick={() => setPeriode(periodeType(p))} className="px-2 py-1 text-xs border rounded-lg dark:border-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">{tt.presets[p]}</button>
          ))}
        </div>
        <label className="text-xs text-gray-500 dark:text-gray-400">{tt.level}
          <select value={niveau} onChange={e => setNiveau(e.target.value as NiveauRapportTaxes)} className={`${classeChamp} block`}>
            {NIVEAUX_RAPPORT_TAXES.map(n => <option key={n} value={n}>{tt.levels[n]}</option>)}
          </select>
        </label>
        {rapport && (
          <div className="ml-auto flex gap-2">
            <button type="button" onClick={() => telechargerRapportTaxes(rapport.du, rapport.au, rapport.niveau).catch(e => setErreur(messageErreur(e, tt.error)))} className={classeBouton}>
              <Download size={16} /> CSV
            </button>
            <button type="button" onClick={() => ouvrirPdf(cheminPdfRapportTaxes(rapport.du, rapport.au, rapport.niveau), `rapport-taxes-${rapport.du}-${rapport.au}.pdf`).catch(e => setErreur(messageErreur(e, tt.error)))}
              className={classeBouton}><FileDown size={16} /> PDF</button>
          </div>
        )}
      </div>
      <p className="text-xs text-gray-500 max-w-4xl">{tt.help}</p>
      {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}

      {rapport && (
        <>
          <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-x-auto">
            <table className="w-full text-sm" aria-label={tt.title}>
              <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                <tr>
                  {(detail || parJour) && <th rowSpan={2} className="px-3 py-2 text-left">{tt.date}</th>}
                  {detail && (
                    <>
                      <th rowSpan={2} className="px-3 py-2 text-left">{tt.type}</th>
                      <th rowSpan={2} className="px-3 py-2 text-left">{tt.number}</th>
                      <th rowSpan={2} className="px-3 py-2 text-left">{tt.party}</th>
                    </>
                  )}
                  <th rowSpan={2} className="px-3 py-2 text-right">{tt.sales}</th>
                  <th rowSpan={2} className="px-3 py-2 text-right">{tt.purchases}</th>
                  {taxes.map(taxe => <th key={taxe.cle} colSpan={2} className="px-3 pt-2 text-center border-l dark:border-gray-600">{taxe.nom}</th>)}
                </tr>
                <tr>
                  {taxes.flatMap(taxe => [
                    <th key={`${taxe.cle}-c`} className="px-3 pb-2 text-right font-normal border-l dark:border-gray-600">{tt.collected}</th>,
                    <th key={`${taxe.cle}-d`} className="px-3 pb-2 text-right font-normal">{tt.deductible}</th>,
                  ])}
                </tr>
              </thead>
              <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                {rapport.lignes.length === 0 && <tr><td colSpan={colonnesDebut + 2 + 2 * taxes.length} className="px-3 py-3 text-gray-500">{tt.none}</td></tr>}
                {rapport.lignes.map((ligne, i) => (
                  <tr key={`${ligne.type ?? ''}${ligne.id ?? ''}-${ligne.date ?? ''}-${i}`} className={ligne.type === 'avoir' ? 'text-red-700 dark:text-red-300' : ''}>
                    {debutLigne(ligne)}
                    {montants(ligne)}
                  </tr>
                ))}
              </tbody>
              {rapport.niveau !== 'periode' && rapport.lignes.length > 0 && (
                <tfoot className="font-semibold bg-gray-50 dark:bg-gray-700/40 dark:text-white">
                  <tr>
                    {colonnesDebut > 0 && <td colSpan={colonnesDebut} className="px-3 py-2">{tt.total}</td>}
                    {montants(rapport.totaux)}
                  </tr>
                </tfoot>
              )}
            </table>
            {rapport.tronque && <p className="px-4 py-2 text-xs text-gray-500">{tt.truncated}</p>}
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-x-auto">
              <table className="w-full text-sm" aria-label={tt.summary}>
                <caption className="text-left px-4 pt-3 pb-1 font-semibold dark:text-white">{tt.summary}</caption>
                <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                  <tr>
                    <th className="px-3 py-2 text-left">{tt.tax}</th>
                    <th className="px-3 py-2 text-right">{tt.collected}</th>
                    <th className="px-3 py-2 text-right">{tt.deductible}</th>
                    <th className="px-3 py-2 text-right">{tt.difference}</th>
                  </tr>
                </thead>
                <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                  {rapport.sommaire.map(taxe => (
                    <tr key={taxe.cle}>
                      <td className="px-3 py-2">{taxe.nom}</td>
                      <td className="px-3 py-2 text-right whitespace-nowrap">{montant(taxe.collectee)}</td>
                      <td className="px-3 py-2 text-right whitespace-nowrap">{montant(taxe.deductible)}</td>
                      <td className="px-3 py-2 text-right whitespace-nowrap">{montant(taxe.difference)}</td>
                    </tr>
                  ))}
                  <tr className="font-semibold bg-gray-50 dark:bg-gray-700/40">
                    <td className="px-3 py-2">{rapport.total.difference >= 0 ? tt.toPay : tt.toRecover}</td>
                    <td className="px-3 py-2 text-right whitespace-nowrap">{montant(rapport.total.collectee)}</td>
                    <td className="px-3 py-2 text-right whitespace-nowrap">{montant(rapport.total.deductible)}</td>
                    <td className="px-3 py-2 text-right whitespace-nowrap">{montant(rapport.total.difference)}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-x-auto">
              {rapport.comptes ? (
                <table className="w-full text-sm" aria-label={tt.accounts}>
                  <caption className="text-left px-4 pt-3 pb-1 font-semibold dark:text-white">{tt.accounts}</caption>
                  <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                    <tr>
                      <th className="px-3 py-2 text-left">{tt.account}</th>
                      <th className="px-3 py-2 text-right">{tt.debit}</th>
                      <th className="px-3 py-2 text-right">{tt.credit}</th>
                      <th className="px-3 py-2 text-right">{tt.balance}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                    {rapport.comptes.lignes.map(c => (
                      <tr key={c.id}>
                        <td className="px-3 py-2"><span className="font-mono text-xs">{c.numero}</span> {c.libelle}<span className="block text-xs text-gray-500">{c.roles.map(r => tt.roles[r]).join(', ')}</span></td>
                        <td className="px-3 py-2 text-right whitespace-nowrap">{montant(c.debit)}</td>
                        <td className="px-3 py-2 text-right whitespace-nowrap">{montant(c.credit)}</td>
                        <td className="px-3 py-2 text-right whitespace-nowrap">{montant(c.solde)}</td>
                      </tr>
                    ))}
                    <tr className="font-semibold bg-gray-50 dark:bg-gray-700/40">
                      <td colSpan={3} className="px-3 py-2">{tt.journalNet}</td>
                      <td className="px-3 py-2 text-right whitespace-nowrap">{montant(rapport.comptes.solde)}</td>
                    </tr>
                  </tbody>
                </table>
              ) : <p className="p-4 text-sm text-gray-500">{tt.noPosting}</p>}
              {rapport.comptes && Math.abs(rapport.comptes.solde - rapport.total.difference) >= 0.005 && (
                <p className="px-4 py-2 text-xs text-amber-700 dark:text-amber-300">{tt.gap.replace('{montant}', montant(rapport.comptes.solde - rapport.total.difference))}</p>
              )}
            </div>
          </div>
        </>
      )}
    </div>
  );
}
