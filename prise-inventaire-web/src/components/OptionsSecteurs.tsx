import { useLanguage } from '@/i18n/useLanguage';
import { secteursParEntrepot } from '@/lib/entrepots';
import type { Secteur } from '@/types';

/**
 * Options d'une liste de secteurs, groupées par entrepôt quand l'entreprise en a (l'entrepôt préféré d'abord, les
 * secteurs sans entrepôt à la fin) ; sinon une simple liste, comme avant.
 */
export default function OptionsSecteurs({ secteurs, valeur = s => String(s.id), libelle = s => `${s.code} - ${s.nom}`, prefere }: {
  secteurs: Secteur[];
  valeur?: (secteur: Secteur) => string;
  libelle?: (secteur: Secteur) => string;
  prefere?: number | null;
}) {
  const { t } = useLanguage();
  const option = (s: Secteur) => <option key={s.id ?? s.code} value={valeur(s)}>{libelle(s)}</option>;

  return (
    <>
      {secteursParEntrepot(secteurs, prefere, t.warehouses.none).map(groupe => (groupe.titre === null
        ? groupe.secteurs.map(option)
        : <optgroup key={groupe.titre} label={groupe.titre}>{groupe.secteurs.map(option)}</optgroup>))}
    </>
  );
}
