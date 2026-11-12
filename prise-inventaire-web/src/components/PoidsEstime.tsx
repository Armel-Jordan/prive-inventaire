import { useLanguage } from '@/i18n/useLanguage';

/** Poids estimé d'un document (socle S4.1), dans une case de son détail ; rien si aucun produit n'a de poids. */
export default function PoidsEstime({ poids }: { poids?: number | null }) {
  const { t, language } = useLanguage();
  if (poids === null || poids === undefined) return null;

  return (
    <div>
      <span className="text-sm text-gray-500">{t.requiredDate.weight} :</span>
      <p className="dark:text-white">{Number(poids).toLocaleString(language === 'en' ? 'en-US' : 'fr-FR')} kg</p>
    </div>
  );
}
