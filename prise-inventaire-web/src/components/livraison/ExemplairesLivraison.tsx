import { useLanguage } from '@/i18n/useLanguage';
import { formatDateDocument } from '@/lib/documents';
import { numerosSaisis, premiersSortis } from '@/lib/lots';
import type { ExemplairesLigneBL } from '@/services/api';

const classeChamp = 'w-full px-2 py-1 border rounded text-sm dark:bg-gray-600 dark:border-gray-500 dark:text-white';

/**
 * Exemplaires d'une ligne livrée (produit suivi) : pour un lot, la quantité qui sort de chaque lot (proposée : le
 * premier à périmer d'abord) ; pour un numéro de série, les numéros livrés. Ce que les lots ne couvrent pas sort sans
 * lot (stock reçu avant le suivi).
 */
export default function ExemplairesLivraison({ exemplaire, unites, allocations, onAllocations, series, onSeries }: {
  exemplaire: ExemplairesLigneBL;
  /** Quantité livrée en unités de stock. */
  unites: number;
  allocations: Record<number, number>;
  onAllocations: (allocations: Record<number, number>) => void;
  series: string;
  onSeries: (texte: string) => void;
}) {
  const { t, language } = useLanguage();
  const tl = t.lots;
  const nombre = (v: number) => v.toLocaleString(language === 'en' ? 'en-US' : 'fr-FR', { maximumFractionDigits: 4 });

  if (exemplaire.suivi === 'serie') {
    const saisis = numerosSaisis(series).length;
    return (
      <label className="block mt-2 text-xs text-gray-600 dark:text-gray-300">
        {tl.deliverySerials}
        <span className={`ml-2 font-medium ${saisis === unites ? 'text-green-700' : 'text-amber-700'}`}>{saisis} / {unites}</span>
        <textarea value={series} rows={Math.min(5, Math.max(2, unites))} onChange={e => onSeries(e.target.value)} className={`${classeChamp} font-mono mt-1`} />
        {exemplaire.series.length > 0 && (
          <span className="block mt-1 text-gray-500 dark:text-gray-400">
            {tl.inStock.replace('{liste}', exemplaire.series.slice(0, 30).join(', ') + (exemplaire.series.length > 30 ? ' …' : ''))}
          </span>
        )}
      </label>
    );
  }

  const total = Object.values(allocations).reduce((s, q) => s + (q || 0), 0);
  return (
    <div className="mt-2 text-xs text-gray-600 dark:text-gray-300">
      <div className="flex items-center justify-between gap-2">
        <span>{tl.deliveryLots}</span>
        <button type="button" onClick={() => onAllocations(premiersSortis(exemplaire.lots, unites))} className="text-purple-700 hover:underline dark:text-purple-300">{tl.fefo}</button>
      </div>
      <div className="mt-1 space-y-1">
        {exemplaire.lots.map(lot => (
          <label key={lot.id} className="flex items-center gap-2">
            <span className="font-mono w-28 truncate" title={lot.numero}>{lot.numero}</span>
            <span className="w-24 text-gray-500 dark:text-gray-400">{lot.date_peremption ? formatDateDocument(lot.date_peremption, language) : '—'}</span>
            <span className="w-20 text-gray-500 dark:text-gray-400">{tl.left.replace('{n}', nombre(lot.solde))}</span>
            <input type="number" min={0} max={lot.solde} step="any" value={allocations[lot.id] ?? 0} aria-label={`${tl.lot} ${lot.numero}`}
              onChange={e => onAllocations({ ...allocations, [lot.id]: Math.max(0, Math.min(lot.solde, Number(e.target.value) || 0)) })}
              className={`${classeChamp} w-20 text-center`} />
          </label>
        ))}
      </div>
      {total > unites && <p className="mt-1 text-red-700 dark:text-red-400">{tl.tooMany}</p>}
      {total < unites && <p className="mt-1 text-amber-700 dark:text-amber-400">{tl.withoutLot.replace('{n}', nombre(unites - total))}</p>}
    </div>
  );
}
