import type { Representant } from '@/hooks/useRepresentants';
import { useLanguage } from '@/i18n/useLanguage';

interface Props {
  id?: string;
  value: number | null;
  onChange: (representantId: number | null) => void;
  representants: Representant[];
  /** Libellé du choix vide : « Aucun représentant » dans une fiche, « Tous les représentants » dans un filtre. */
  libelleVide?: string;
  ariaLabel?: string;
  disabled?: boolean;
  className?: string;
}

/**
 * Choix du représentant commercial (phase 1.11a) : les représentants actifs, plus celui déjà retenu s'il a été
 * désactivé depuis (il reste affiché, marqué « inactif »).
 */
export default function ChoixRepresentant({ id, value, onChange, representants, libelleVide, ariaLabel, disabled, className }: Props) {
  const { t } = useLanguage();
  const tr = t.salesReps;
  const proposes = representants.filter(r => r.actif || r.id === value);

  return (
    <select id={id} value={value ?? ''} disabled={disabled} aria-label={ariaLabel}
      onChange={e => onChange(e.target.value ? Number(e.target.value) : null)}
      className={className ?? 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white'}>
      <option value="">{libelleVide ?? tr.none}</option>
      {proposes.map(r => <option key={r.id} value={r.id}>{r.nom}{r.actif ? '' : ` (${tr.inactive})`}</option>)}
    </select>
  );
}
