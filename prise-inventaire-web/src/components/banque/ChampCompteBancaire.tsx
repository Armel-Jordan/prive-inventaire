import { useEffect, useState } from 'react';
import { useLanguage } from '@/i18n/useLanguage';
import { getReferentiel, type FicheReferentiel } from '@/services/api';

/**
 * Compte bancaire par lequel passe l'argent d'un encaissement ou d'un déboursé (chèque, virement, carte) : les comptes
 * bancaires actifs de l'entreprise ; le compte « par défaut » est proposé. Vide : le compte de banque des réglages de
 * comptabilisation. Sans compte bancaire, le champ n'apparaît pas.
 */
export default function ChampCompteBancaire({ valeur, onChange, className = '' }: {
  valeur: string;
  onChange: (valeur: string) => void;
  className?: string;
}) {
  const { t } = useLanguage();
  const [comptes, setComptes] = useState<FicheReferentiel[]>([]);

  useEffect(() => {
    let actif = true;
    getReferentiel('comptes_bancaires', true).then(liste => {
      if (!actif) return;
      setComptes(liste ?? []);
      const defaut = (liste ?? []).find(c => c.par_defaut);
      if (defaut && valeur === '') onChange(String(defaut.id));
    }, () => {});
    return () => { actif = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- le compte par défaut n'est proposé qu'au premier chargement
  }, []);

  if (comptes.length === 0) return null;
  return (
    <label className={`block text-xs text-gray-500 dark:text-gray-400 ${className}`}>{t.bank.bankAccountField}
      <select value={valeur} onChange={e => onChange(e.target.value)}
        className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm">
        <option value="">{t.bank.bankAccountNone}</option>
        {comptes.map(c => <option key={c.id} value={c.id}>{String(c.nom)}{c.devise_code ? ` (${String(c.devise_code)})` : ''}</option>)}
      </select>
    </label>
  );
}
