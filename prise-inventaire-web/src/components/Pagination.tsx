import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';

interface Props {
  page: number;
  dernierePage: number;
  total: number;
  onPage: (page: number) => void;
}

/**
 * Pied de liste paginée : nombre total et pages précédente / suivante. Les listes n'affichaient que la
 * première page renvoyée par l'API (15 ou 20 éléments) sans moyen d'aller plus loin.
 */
export default function Pagination({ page, dernierePage, total, onPage }: Props) {
  const { t } = useLanguage();
  const tp = t.pagination;
  if (dernierePage <= 1) return null;

  const bouton = 'inline-flex items-center gap-1 px-3 py-1.5 text-sm rounded-lg border dark:border-gray-600 dark:text-white hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-40 disabled:hover:bg-transparent';

  return (
    <nav aria-label={tp.label} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-t dark:border-gray-700">
      <span className="text-sm text-gray-500 dark:text-gray-400">{tp.total.replace('{n}', String(total))}</span>
      <div className="flex items-center gap-2">
        <button type="button" className={bouton} disabled={page <= 1} onClick={() => onPage(page - 1)}>
          <ChevronLeft size={16} /> {tp.previous}
        </button>
        <span className="text-sm text-gray-600 dark:text-gray-300" aria-current="page">
          {tp.page.replace('{page}', String(page)).replace('{pages}', String(dernierePage))}
        </span>
        <button type="button" className={bouton} disabled={page >= dernierePage} onClick={() => onPage(page + 1)}>
          {tp.next} <ChevronRight size={16} />
        </button>
      </div>
    </nav>
  );
}
