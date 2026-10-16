import type { RecapitulatifTaxe } from '@/services/api';
import { useLanguage } from '@/i18n/useLanguage';
import { useMontantDocument } from '@/lib/documents';

/**
 * Détail d'un document par taxe (TVA, CAC, TPS…) ; n'affiche rien pour le calcul au taux unique. Montants dans la
 * devise du document (celle de l'entreprise, ou celle d'un client étranger), et non en euros d'office.
 */
export default function RecapitulatifTaxes({ recapitulatif, devise }: { recapitulatif?: RecapitulatifTaxe[]; devise?: string | null }) {
  const { t, language } = useLanguage();
  const montantDoc = useMontantDocument();
  if (!recapitulatif || recapitulatif.length === 0) return null;

  const locale = language === 'en' ? 'en-US' : 'fr-FR';
  const montant = (v: number) => montantDoc(v, devise);

  return (
    <div className="mt-1 mb-1 pl-3 border-l-2 border-gray-200 dark:border-gray-600 space-y-0.5">
      {recapitulatif.map(taxe => (
        <div key={`${taxe.nom}-${taxe.taux}`} className="flex justify-between text-sm">
          <span className="text-gray-500">
            {taxe.nom} {taxe.taux.toLocaleString(locale, { maximumFractionDigits: 4 })} % {t.taxGroups.on} {montant(taxe.base)}
          </span>
          <span className="dark:text-white">{montant(taxe.montant)}</span>
        </div>
      ))}
    </div>
  );
}
