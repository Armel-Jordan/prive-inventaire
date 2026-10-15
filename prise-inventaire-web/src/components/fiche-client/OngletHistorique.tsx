import { useEffect, useId, useState } from 'react';
import { getHistoriqueAchatsClient, type HistoriqueAchats } from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { formatDate, formatMontant } from './format';

/** Historique d'achats : lignes des factures du client, filtrées par période et par produit. */
export default function OngletHistorique({ clientId, devise }: { clientId: number; devise: string }) {
  const { t, language } = useLanguage();
  const th = t.clientFile.history;
  const ts = t.clientFile.summary;
  const id = useId();
  const [filtres, setFiltres] = useState({ du: '', au: '', recherche: '' });
  const [recherche, setRecherche] = useState('');
  const [page, setPage] = useState(1);
  const [historique, setHistorique] = useState<HistoriqueAchats | null>(null);
  const [erreur, setErreur] = useState('');

  // La recherche par produit attend une courte pause de saisie.
  useEffect(() => {
    if (recherche === filtres.recherche) return;
    const minuterie = setTimeout(() => { setFiltres(f => ({ ...f, recherche })); setPage(1); }, 300);
    return () => clearTimeout(minuterie);
  }, [recherche, filtres.recherche]);

  useEffect(() => {
    let actif = true;
    getHistoriqueAchatsClient(clientId, { ...filtres, page })
      .then(h => { if (actif) { setHistorique(h); setErreur(''); } })
      .catch(e => { if (actif) setErreur(e instanceof ApiError ? e.message : t.clientFile.error); });
    return () => { actif = false; };
  }, [clientId, filtres, page, t.clientFile.error]);

  const montant = (v: number) => formatMontant(v, devise, language);
  const champ = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-3">
        <div>
          <label htmlFor={`${id}-du`} className="block text-xs text-gray-500 mb-1">{th.from}</label>
          <input id={`${id}-du`} type="date" value={filtres.du} onChange={e => { setFiltres({ ...filtres, du: e.target.value }); setPage(1); }} className={champ} />
        </div>
        <div>
          <label htmlFor={`${id}-au`} className="block text-xs text-gray-500 mb-1">{th.to}</label>
          <input id={`${id}-au`} type="date" value={filtres.au} onChange={e => { setFiltres({ ...filtres, au: e.target.value }); setPage(1); }} className={champ} />
        </div>
        <div className="flex-1 min-w-[14rem]">
          <label htmlFor={`${id}-produit`} className="block text-xs text-gray-500 mb-1">{th.search}</label>
          <input id={`${id}-produit`} type="search" value={recherche} onChange={e => setRecherche(e.target.value)} className={`${champ} w-full`} />
        </div>
      </div>

      {erreur && <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      {historique && (historique.total === 0 ? (
        <p className="text-sm text-gray-500 dark:text-gray-400">{th.empty}</p>
      ) : (
        <>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                <tr>
                  <th className="px-3 py-2 text-left font-medium">{th.date}</th>
                  <th className="px-3 py-2 text-left font-medium">{th.invoice}</th>
                  <th className="px-3 py-2 text-left font-medium">{ts.product}</th>
                  <th className="px-3 py-2 text-right font-medium">{ts.quantity}</th>
                  <th className="px-3 py-2 text-right font-medium">{th.unitPrice}</th>
                  <th className="px-3 py-2 text-right font-medium">{th.discount}</th>
                  <th className="px-3 py-2 text-right font-medium">{ts.amount}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                {historique.data.map(l => (
                  <tr key={l.id} className="text-gray-800 dark:text-gray-200">
                    <td className="px-3 py-2 whitespace-nowrap">{formatDate(l.date, language)}</td>
                    <td className="px-3 py-2 font-mono text-xs">{l.facture_numero}</td>
                    <td className="px-3 py-2"><span className="font-mono text-xs text-gray-500 mr-2">{l.produit_numero ?? `#${l.produit_id}`}</span>{l.produit_description}</td>
                    <td className="px-3 py-2 text-right">{l.quantite}</td>
                    <td className="px-3 py-2 text-right whitespace-nowrap">{montant(l.prix_unitaire_ht)}</td>
                    <td className="px-3 py-2 text-right">{l.remise_ligne > 0 ? `${l.remise_ligne} %` : '—'}</td>
                    <td className="px-3 py-2 text-right whitespace-nowrap">{montant(l.montant_ht)}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot className="border-t-2 dark:border-gray-600 font-medium text-gray-900 dark:text-white">
                <tr>
                  <td className="px-3 py-2" colSpan={3}>{th.total}</td>
                  <td className="px-3 py-2 text-right">{historique.totaux.quantite}</td>
                  <td className="px-3 py-2" colSpan={2} />
                  <td className="px-3 py-2 text-right whitespace-nowrap">{montant(historique.totaux.montant_ht)}</td>
                </tr>
              </tfoot>
            </table>
          </div>
          {historique.last_page > 1 && (
            <div className="flex items-center justify-end gap-3 text-sm">
              <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} className="px-3 py-1 rounded border dark:border-gray-600 dark:text-white disabled:opacity-40">{th.previous}</button>
              <span className="text-gray-600 dark:text-gray-300">{th.page.replace('{page}', String(historique.current_page)).replace('{pages}', String(historique.last_page))}</span>
              <button disabled={page >= historique.last_page} onClick={() => setPage(p => p + 1)} className="px-3 py-1 rounded border dark:border-gray-600 dark:text-white disabled:opacity-40">{th.next}</button>
            </div>
          )}
        </>
      ))}
    </div>
  );
}
