import type { Transporteur } from '@/hooks/useTransporteurs';
import { useLanguage } from '@/i18n/useLanguage';

interface Props {
  id?: string;
  value: number | null;
  onChange: (transporteurId: number | null) => void;
  transporteurs: Transporteur[];
  /** Libellé du choix vide : « Aucun transporteur » dans une fiche, « Tous les transporteurs » dans un filtre. */
  libelleVide?: string;
  ariaLabel?: string;
  disabled?: boolean;
  className?: string;
}

/**
 * Choix du transporteur (phase 1.11b) : les transporteurs actifs, plus celui déjà retenu s'il a été désactivé depuis
 * (il reste affiché, marqué « inactif »).
 */
export default function ChoixTransporteur({ id, value, onChange, transporteurs, libelleVide, ariaLabel, disabled, className }: Props) {
  const { t } = useLanguage();
  const tr = t.carriers;
  const proposes = transporteurs.filter(c => c.actif || c.id === value);

  return (
    <select id={id} value={value ?? ''} disabled={disabled} aria-label={ariaLabel}
      onChange={e => onChange(e.target.value ? Number(e.target.value) : null)}
      className={className ?? 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white'}>
      <option value="">{libelleVide ?? tr.none}</option>
      {proposes.map(c => <option key={c.id} value={c.id}>{c.nom}{c.actif ? '' : ` (${tr.inactive})`}</option>)}
    </select>
  );
}
