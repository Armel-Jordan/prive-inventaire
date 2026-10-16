import { useEffect, useState } from 'react';
import { useLanguage } from '@/i18n/useLanguage';
import { tauxPropose, useCodeDevise } from '@/lib/documents';
import { getReferentiel } from '@/services/api';

export interface DeviseDocument {
  /** Code ISO ; vide : la devise de l'entreprise. */
  devise: string;
  /** Valeur d'une unité de la devise dans la devise de l'entreprise (vide : à saisir). */
  taux: string;
}

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

/**
 * Devise d'un document de vente et son taux de change. La devise de l'entreprise, ou une devise du référentiel ; pour
 * une devise étrangère, le taux est proposé (référentiel, sinon cours du jour) et se corrige. Les prix du document se
 * saisissent dans sa devise.
 */
export default function ChampDevise({ valeur, onChange, className = '' }: { valeur: DeviseDocument; onChange: (valeur: DeviseDocument) => void; className?: string }) {
  const { t } = useLanguage();
  const tc = t.currency;
  const reference = useCodeDevise();
  const [codes, setCodes] = useState<string[]>([]);

  useEffect(() => {
    let actif = true;
    getReferentiel('devises', true).then(liste => {
      if (actif) setCodes(liste.map(d => String(d.code ?? '').toUpperCase()).filter(Boolean));
    }, () => {});
    return () => { actif = false; };
  }, []);

  const choisir = (devise: string) => {
    onChange({ devise, taux: devise ? '' : '1' });
    if (devise) tauxPropose(devise, reference).then(taux => onChange({ devise, taux }));
  };

  // La devise du document reste proposée même si elle n'est plus active dans le référentiel.
  const options = [...new Set([...codes, valeur.devise].filter(code => code && code !== reference))];
  const etrangere = !!valeur.devise && valeur.devise !== reference;

  return (
    <div className={`grid grid-cols-2 gap-3 ${className}`}>
      <label className="block text-xs text-gray-500 dark:text-gray-400">{tc.label}
        <select value={etrangere ? valeur.devise : ''} onChange={e => choisir(e.target.value)} className={classeChamp}>
          <option value="">{tc.company.replace('{code}', reference || '…')}</option>
          {options.map(code => <option key={code} value={code}>{code}</option>)}
        </select>
      </label>
      {etrangere && (
        <label className="block text-xs text-gray-500 dark:text-gray-400">{tc.rate.replace('{devise}', valeur.devise).replace('{reference}', reference)}
          <input type="number" required min="0.00000001" step="any" value={valeur.taux} placeholder={tc.rateMissing}
            onChange={e => onChange({ devise: valeur.devise, taux: e.target.value })} className={classeChamp} />
        </label>
      )}
    </div>
  );
}
