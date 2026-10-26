import { useEffect, useState } from 'react';
import { getReferentiel, type FicheReferentiel } from '@/services/api';
import { useLanguage } from '@/i18n/useLanguage';

interface Props {
  id: string;
  /** Condition choisie ; vide : celle du client, sinon celle par défaut de l'entreprise. */
  value: number | null;
  onChange: (conditionId: number | null) => void;
  /** Condition que porte déjà le document : reste affichée si elle a été désactivée depuis. */
  actuelle?: { id: number; libelle: string } | null;
}

/** Condition de paiement d'un devis ou d'une commande (socle S4.1), reprise par la facture qui en calcule l'échéance. */
export default function ChoixConditionPaiement({ id, value, onChange, actuelle }: Props) {
  const { t } = useLanguage();
  const [conditions, setConditions] = useState<FicheReferentiel[]>([]);

  useEffect(() => {
    getReferentiel('conditions_paiement', true).then(setConditions).catch(() => setConditions([]));
  }, []);

  const absente = actuelle && actuelle.id === value && !conditions.some(c => c.id === actuelle.id);

  return (
    <select id={id} value={value ?? ''} onChange={e => onChange(e.target.value ? Number(e.target.value) : null)}
      className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white">
      <option value="">{t.documentTerms.clientDefault}</option>
      {absente && <option value={actuelle.id}>{actuelle.libelle}</option>}
      {conditions.map(c => <option key={c.id} value={c.id}>{String(c.libelle)}</option>)}
    </select>
  );
}
