import { Tag } from 'lucide-react';
import type { InfoPrix } from '@/lib/prixDocuments';
import { useLanguage } from '@/i18n/useLanguage';

/** Origine du prix proposé sur une ligne (liste, remise du client, règle), avec la remise obtenue. */
export default function OriginePrix({ info }: { info?: InfoPrix | null }) {
  const { language } = useLanguage();
  if (!info) return null;
  const remise = info.remise > 0 ? `−${new Intl.NumberFormat(language === 'en' ? 'en-US' : 'fr-FR', { maximumFractionDigits: 2 }).format(info.remise)} %` : '';
  const description = remise ? `${info.libelle} (${remise})` : info.libelle;

  return (
    <span title={description} aria-label={description} role="img"
      className="inline-flex shrink-0 items-center gap-0.5 text-[10px] font-medium text-emerald-700 whitespace-nowrap dark:text-emerald-400">
      <Tag size={12} aria-hidden="true" />{remise}
    </span>
  );
}
