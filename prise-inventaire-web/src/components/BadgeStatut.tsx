import { useStatutsDocuments } from '@/hooks/useStatutsDocuments';
import { useLanguage } from '@/i18n/useLanguage';
import type { DocumentAStatuts } from '@/services/statutsDocuments';

/**
 * Badge du statut d'un document, aux libellé et couleurs de l'entreprise (Paramètres → Statuts des documents). Tant
 * que la liste n'est pas chargée (ou pour un statut hors catalogue) : le libellé par défaut de l'écran, en gris.
 */
export default function BadgeStatut({ document, statut, libelleDefaut }: { document: DocumentAStatuts; statut: string; libelleDefaut?: string }) {
  const { language } = useLanguage();
  const liste = useStatutsDocuments();
  const trouve = liste?.[document]?.find(s => s.code === statut);

  return (
    <span className="px-2 py-1 text-xs rounded-full whitespace-nowrap"
      style={trouve ? { backgroundColor: trouve.couleur_fond, color: trouve.couleur_texte } : { backgroundColor: '#f3f4f6', color: '#1f2937' }}>
      {trouve ? (language === 'en' ? trouve.libelle_en : trouve.libelle_fr) : libelleDefaut ?? statut}
    </span>
  );
}
