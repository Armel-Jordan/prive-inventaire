import type { GroupeTaxeChoix } from '@/hooks/useGroupesTaxes';
import { useLanguage } from '@/i18n/useLanguage';

interface Props {
  id?: string;
  value: number | null;
  onChange: (groupeId: number | null) => void;
  groupes: GroupeTaxeChoix[];
  className?: string;
}

/**
 * Groupe de taxes d'une fiche client ou d'un document de vente (socle S4.1) : les groupes actifs, avec leur taux, plus
 * celui déjà retenu s'il a été désactivé depuis (marqué « inactif »). Vide : le groupe par défaut de l'entreprise.
 */
export default function ChoixGroupeTaxe({ id, value, onChange, groupes, className }: Props) {
  const { t, language } = useLanguage();
  const tr = t.taxGroupDoc;
  const proposes = groupes.filter(g => g.actif || g.id === value);
  const taux = (g: GroupeTaxeChoix) => `${g.taux_effectif.toLocaleString(language === 'en' ? 'en-US' : 'fr-FR')} %`;

  return (
    <select id={id} value={value ?? ''} onChange={e => onChange(e.target.value ? Number(e.target.value) : null)}
      className={className ?? 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white'}>
      <option value="">{tr.none}</option>
      {proposes.map(g => <option key={g.id} value={g.id}>{g.nom} — {taux(g)}{g.actif ? '' : ` (${tr.inactive})`}</option>)}
    </select>
  );
}
