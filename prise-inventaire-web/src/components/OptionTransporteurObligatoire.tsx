import { useEffect, useState } from 'react';
import { getParametres, updateParametres, type TenantParametres } from '@/services/api';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { oublierTransporteurObligatoire } from '@/hooks/useTransporteurs';
import { signaler } from '@/lib/erreurs';

/**
 * Option « Transporteur obligatoire » de l'entreprise (phase 1.11b), dans l'onglet Transporteurs des référentiels :
 * un devis, une commande, une facture ou une commande fournisseur saisis doivent alors avoir un transporteur. Elle se
 * règle avec le droit de modifier la configuration ; elle est enregistrée tout de suite.
 */
export default function OptionTransporteurObligatoire() {
  const { t } = useLanguage();
  const { peut } = usePermissions();
  const [parametres, setParametres] = useState<TenantParametres | null>(null);
  const [enCours, setEnCours] = useState(false);

  useEffect(() => {
    let actif = true;
    getParametres().then(p => { if (actif) setParametres(p); }, () => {});
    return () => { actif = false; };
  }, []);

  if (!parametres) return null;

  async function changer(obligatoire: boolean) {
    if (!parametres) return;
    setEnCours(true);
    try {
      const { parametres: enregistres } = await updateParametres({ ...parametres, transporteur_obligatoire: obligatoire });
      setParametres(enregistres);
      oublierTransporteurObligatoire();
    } catch (erreur) {
      signaler(erreur, t.carriers.mandatory);
    } finally {
      setEnCours(false);
    }
  }

  return (
    <label className="flex items-start gap-3 mb-4 p-3 rounded-lg bg-white dark:bg-gray-800 shadow-sm cursor-pointer">
      <input type="checkbox" className="mt-1 w-4 h-4 rounded" checked={!!parametres.transporteur_obligatoire}
        disabled={enCours || !peut('configuration', 'modifier')} onChange={e => changer(e.target.checked)} />
      <span>
        <span className="block text-sm font-medium text-gray-700 dark:text-gray-200">{t.carriers.mandatory}</span>
        <span className="block text-xs text-gray-500">{t.carriers.mandatoryHelp}</span>
      </span>
    </label>
  );
}
