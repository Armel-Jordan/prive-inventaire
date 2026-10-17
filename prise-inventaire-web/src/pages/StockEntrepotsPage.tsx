import { useEffect, useState } from 'react';
import { Download, Warehouse } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { getStockEntrepots, telechargerStockEntrepots, type StockEntrepots } from '@/services/stockEntrepots';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const classeEntete = 'px-3 py-2 text-right text-xs font-medium text-gray-600 dark:text-gray-300 whitespace-nowrap';

/**
 * Stock par entrepôt (plan de parité fripv2, phase 2.5) : la somme des scans de chaque produit, répartie selon
 * l'entrepôt de leur secteur, avec les totaux ; filtre par entrepôt, recherche, export CSV.
 */
export default function StockEntrepotsPage() {
  const { t, language } = useLanguage();
  const tw = t.warehouses;
  const [recherche, setRecherche] = useState('');
  const [terme, setTerme] = useState('');
  const [entrepot, setEntrepot] = useState('');
  const [resultat, setResultat] = useState<StockEntrepots | null>(null);
  const [erreur, setErreur] = useState('');
  const [export_, setExport] = useState(false);
  const nombre = (v: number) => v.toLocaleString(language === 'en' ? 'en-US' : 'fr-FR', { maximumFractionDigits: 4 });
  const cellule = (v: number | undefined) => (v ? nombre(v) : <span className="text-gray-300 dark:text-gray-600">—</span>);

  useEffect(() => {
    const minuterie = setTimeout(() => setTerme(recherche.trim()), 300);
    return () => clearTimeout(minuterie);
  }, [recherche]);

  const filtres = { recherche: terme || undefined, entrepot_id: entrepot ? Number(entrepot) : undefined };
  useEffect(() => {
    let actif = true;
    getStockEntrepots({ recherche: terme || undefined, entrepot_id: entrepot ? Number(entrepot) : undefined }).then(
      r => { if (actif) { setResultat(r); setErreur(''); } },
      e => { if (actif) setErreur(messageErreur(e, tw.error)); },
    );
    return () => { actif = false; };
  }, [terme, entrepot, tw.error]);

  async function exporter() {
    setExport(true);
    try {
      await telechargerStockEntrepots(filtres);
    } catch (e) {
      setErreur(messageErreur(e, tw.error));
    } finally {
      setExport(false);
    }
  }

  const colonnes = resultat?.entrepots ?? [];

  return (
    <div className="p-4 sm:p-6">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Warehouse className="text-teal-600" /> {tw.stockTitle}</h1>
        <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tw.stockSubtitle}</p>
      </div>

      <div className="flex flex-wrap items-end gap-2 mb-4">
        <label className="text-xs text-gray-500 dark:text-gray-400 flex-1 min-w-[12rem]">{tw.search}
          <input type="search" value={recherche} onChange={e => setRecherche(e.target.value)} placeholder={tw.searchHelp} className={classeChamp} />
        </label>
        {colonnes.length > 0 && (
          <label className="text-xs text-gray-500 dark:text-gray-400 w-64">{tw.warehouse}
            <select value={entrepot} onChange={e => setEntrepot(e.target.value)} className={classeChamp}>
              <option value="">{tw.all}</option>
              {colonnes.map(e => <option key={e.id} value={String(e.id)}>{e.code} — {e.nom}</option>)}
            </select>
          </label>
        )}
        <button type="button" onClick={exporter} disabled={export_ || !resultat?.produits.length}
          className="inline-flex items-center gap-2 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50">
          <Download size={16} /> {tw.export}
        </button>
      </div>

      {resultat && colonnes.length === 0 && (
        <p className="mb-3 px-3 py-2 rounded-lg text-sm bg-blue-50 text-blue-800 dark:bg-blue-900/30 dark:text-blue-200">{tw.noWarehouse}</p>
      )}
      {erreur && <p role="alert" className="mb-3 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300">{erreur}</p>}

      {!resultat ? (
        !erreur && <p className="text-sm text-gray-500">{t.common.loading}</p>
      ) : resultat.produits.length === 0 ? (
        <p className="text-sm text-gray-500 dark:text-gray-400">{tw.empty}</p>
      ) : (
        <>
          <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">{tw.count.replace('{n}', String(resultat.produits.length))}</p>
          <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 dark:bg-gray-700 border-b dark:border-gray-600">
                <tr>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-600 dark:text-gray-300">{tw.product}</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-600 dark:text-gray-300">{tw.unit}</th>
                  {colonnes.map(e => (
                    <th key={e.id} className={`${classeEntete} ${e.actif ? '' : 'italic text-gray-400'}`} title={`${e.nom}${e.actif ? '' : ` (${tw.inactive})`}`}>{e.code}</th>
                  ))}
                  <th className={classeEntete}>{tw.none}</th>
                  <th className={classeEntete}>{tw.total}</th>
                </tr>
              </thead>
              <tbody className="divide-y dark:divide-gray-700">
                {resultat.produits.map(p => (
                  <tr key={p.numero} className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                    <td className="px-3 py-2 dark:text-gray-200">
                      <span className="font-mono font-medium">{p.numero}</span>
                      {p.description && <span className="block text-xs text-gray-500 dark:text-gray-400">{p.description}</span>}
                    </td>
                    <td className="px-3 py-2 text-gray-500 dark:text-gray-400">{p.mesure ?? ''}</td>
                    {colonnes.map(e => <td key={e.id} className="px-3 py-2 text-right tabular-nums dark:text-gray-200">{cellule(p.entrepots[e.id])}</td>)}
                    <td className="px-3 py-2 text-right tabular-nums dark:text-gray-200">{cellule(p.sans_entrepot)}</td>
                    <td className="px-3 py-2 text-right tabular-nums font-semibold dark:text-white">{nombre(p.total)}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot className="bg-gray-50 dark:bg-gray-700 border-t dark:border-gray-600 font-semibold">
                <tr>
                  <td className="px-3 py-2 dark:text-white" colSpan={2}>{tw.totals}</td>
                  {colonnes.map(e => <td key={e.id} className="px-3 py-2 text-right tabular-nums dark:text-white">{cellule(resultat.totaux.entrepots[e.id])}</td>)}
                  <td className="px-3 py-2 text-right tabular-nums dark:text-white">{cellule(resultat.totaux.sans_entrepot)}</td>
                  <td className="px-3 py-2 text-right tabular-nums dark:text-white">{nombre(resultat.totaux.total)}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </>
      )}
    </div>
  );
}
