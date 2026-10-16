import type { StatutCourriel } from '@/services/api';
import { useLanguage } from '@/i18n/useLanguage';

const COULEURS: Record<StatutCourriel, string> = {
  en_attente: 'bg-amber-100 text-amber-800',
  en_cours: 'bg-blue-100 text-blue-800',
  envoye: 'bg-green-100 text-green-800',
  echec: 'bg-red-100 text-red-800',
};

/** Statut d'un courriel de la file d'envoi ; le titre peut porter la dernière erreur. */
export default function BadgeStatutCourriel({ statut, titre }: { statut: StatutCourriel; titre?: string | null }) {
  const { t } = useLanguage();

  return (
    <span title={titre ?? undefined} className={`px-2 py-0.5 rounded text-xs font-medium whitespace-nowrap ${COULEURS[statut]}`}>
      {t.emails.statuses[statut]}
    </span>
  );
}
