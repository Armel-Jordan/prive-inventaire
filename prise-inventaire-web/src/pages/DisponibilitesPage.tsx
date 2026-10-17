import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { PackageSearch } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { getDisponibilites, type Disponibilite } from '@/services/disponibilites';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

/**
 * Disponibilité des produits (plan de parité fripv2, phase 1.4 : réservation de stock) : ce que les commandes client
 * acceptées réservent sur le stock compté, ce qui manque, ce qui est en commande chez les fournisseurs et ce que les
 * clients attendent en reliquat.
 */
export default function DisponibilitesPage() {
  const { t, language } = useLanguage();
  const td = t.availability;
  const [recherche, setRecherche] = useState('');
  const [terme, setTerme] = useState('');
  const [filtre, setFiltre] = useState<'engages' | 'manques' | 'tous'>('engages');
  const [resultat, setResultat] = useState<{ data: Disponibilite[]; resume: { produits: number; en_manque: number } } | null>(null);
  const [erreur, setErreur] = useState('');
  const nombre = (v: number) => v.toLocaleString(language === 'en' ? 'en-US' : 'fr-FR', { maximumFractionDigits: 4 });

  useEffect(() => {
    const minuterie = setTimeout(() => setTerme(recherche.trim()), 300);
    return () => clearTimeout(minuterie);
  }, [recherche]);

  useEffect(() => {
    let actif = true;
    getDisponibilites({ recherche: terme || undefined, manques: filtre === 'manques', tous: filtre === 'tous' }).then(
      r => { if (actif) { setResultat(r); setErreur(''); } },
      e => { if (actif) setErreur(messageErreur(e, td.error)); },
    );
    return () => { actif = false; };
  }, [terme, filtre, td.error]);

  return (
    <div className="p-4 sm:p-6">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><PackageSearch className="text-teal-600" /> {td.title}</h1>
        <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{td.subtitle}</p>
      </div>

      <div className="flex flex-wrap items-end gap-2 mb-4">
        <label className="text-xs text-gray-500 dark:text-gray-400 flex-1 min-w-[12rem]">{td.search}
          <input type="search" value={recherche} onChange={e => setRecherche(e.target.value)} placeholder={td.searchHelp} className={classeChamp} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400 w-56">{td.show}
          <select value={filtre} onChange={e => setFiltre(e.target.value as typeof filtre)} className={classeChamp}>
            <option value="engages">{td.filters.engages}</option>
            <option value="manques">{td.filters.manques}</option>
            <option value="tous">{td.filters.tous}</option>
          </select>
        </label>
      </div>

      {resultat && resultat.resume.en_manque > 0 && filtre !== 'manques' && (
        <p className="mb-3 px-3 py-2 rounded-lg text-sm bg-amber-50 text-amber-800">{td.shortages.replace('{n}', String(resultat.resume.en_manque))}</p>
      )}
      {erreur && <p role="alert" className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto">
        <table className="w-full text-sm min-w-[52rem]">
          <thead className="bg-gray-50 dark:bg-gray-700">
            <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
              <th className="px-3 py-2 font-medium">{td.product}</th>
              <th className="px-3 py-2 font-medium text-right">{td.stock}</th>
              <th className="px-3 py-2 font-medium text-right">{td.reserved}</th>
              <th className="px-3 py-2 font-medium text-right">{td.available}</th>
              <th className="px-3 py-2 font-medium text-right">{td.onOrder}</th>
              <th className="px-3 py-2 font-medium text-right">{td.backOrders}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
            {resultat === null && !erreur && <tr><td colSpan={6} className="px-4 py-6 text-center text-gray-500">{td.loading}</td></tr>}
            {resultat !== null && resultat.data.length === 0 && <tr><td colSpan={6} className="px-4 py-6 text-center text-gray-500">{filtre === 'engages' ? td.nothingCommitted : td.empty}</td></tr>}
            {(resultat?.data ?? []).map(d => (
              <tr key={d.produit_id} className={d.manque > 0 ? 'bg-red-50/60 dark:bg-red-900/10' : 'hover:bg-gray-50 dark:hover:bg-gray-700/50'}>
                <td className="px-3 py-2 text-gray-900 dark:text-white">
                  {d.description}
                  {d.ensemble && (
                    <span className="ml-2 px-1.5 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300"
                      title={td.kitHelp.replace('{composants}', (d.composants ?? []).map(c => `${Number(c.quantite)} × ${c.numero}`).join(', '))}>{td.kit}</span>
                  )}
                  <span className="block text-xs font-mono text-gray-500">{d.numero}{d.mesure ? ` · ${d.mesure}` : ''}</span>
                  {d.ensemble && (d.composants ?? []).length > 0 && (
                    <span className="block text-xs text-gray-500">{(d.composants ?? []).map(c => `${Number(c.quantite)} × ${c.numero} (${nombre(c.disponible)})`).join(' · ')}</span>
                  )}
                  {d.manque > 0 && (d.equivalents ?? []).length > 0 && (
                    <span className="block text-xs text-emerald-700 dark:text-emerald-400">
                      {td.equivalents.replace('{liste}', (d.equivalents ?? []).map(e => `${e.numero} (${nombre(e.disponible)})${e.remarque ? ` — ${e.remarque}` : ''}`).join(', '))}
                    </span>
                  )}
                </td>
                <td className="px-3 py-2 text-right text-gray-700 dark:text-gray-300">{nombre(d.stock)}</td>
                <td className="px-3 py-2 text-right text-gray-700 dark:text-gray-300">{nombre(d.reserve)}</td>
                <td className={`px-3 py-2 text-right font-semibold ${d.disponible < 0 ? 'text-red-700 dark:text-red-400' : 'text-gray-900 dark:text-white'}`}>
                  {nombre(d.disponible)}
                  {d.manque > 0 && <span className="block text-xs font-normal">{td.missing.replace('{n}', nombre(d.manque))}</span>}
                </td>
                <td className="px-3 py-2 text-right text-gray-700 dark:text-gray-300">{nombre(d.en_commande)}</td>
                <td className="px-3 py-2 text-right text-gray-700 dark:text-gray-300">
                  {d.reliquats > 0 ? <Link to={`/reliquats-clients?recherche=${encodeURIComponent(d.numero)}`} className="text-sky-700 hover:underline">{nombre(d.reliquats)}</Link> : '0'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">{td.howItWorks}</p>
    </div>
  );
}
