import type { DefinitionRapport, FrequenceProgramme } from '@/services/bibliothequeRapports';

/** Période relative proposée d'office pour chaque fréquence : la veille, la semaine précédente, le mois précédent. */
export const PERIODE_PAR_FREQUENCE: Record<FrequenceProgramme, string> = {
  quotidienne: 'veille',
  hebdomadaire: 'semaine_precedente',
  mensuelle: 'mois_precedent',
};

/** Le rapport a-t-il une période (paramètres « du » et « au ») ? Programmé, elle est relative. */
export const aUnePeriode = (rapport: DefinitionRapport) => ['du', 'au'].every(cle => rapport.parametres.some(p => p.cle === cle));

/** Paramètres réglés dans un envoi programmé : tous, sauf la période d'un rapport qui en a une. */
export const parametresProgrammables = (rapport: DefinitionRapport) =>
  aUnePeriode(rapport) ? rapport.parametres.filter(p => p.cle !== 'du' && p.cle !== 'au') : rapport.parametres;

/** Libellés de la fréquence d'un envoi : « Chaque lundi », « Le 1er de chaque mois »… */
export interface LibellesFrequence {
  frequencies: Record<FrequenceProgramme, string>;
  everyWeekdays: readonly string[];
  monthFirstDay: string;
  monthDay: string;
  monthLastDay: string;
}

export function decrireFrequence(frequence: FrequenceProgramme, jour: number | null, libelles: LibellesFrequence): string {
  if (frequence === 'hebdomadaire' && jour) return libelles.everyWeekdays[jour - 1] ?? libelles.frequencies.hebdomadaire;
  if (frequence === 'mensuelle' && jour) {
    if (jour === 1) return libelles.monthFirstDay;
    if (jour === 31) return libelles.monthLastDay;
    return libelles.monthDay.replace('{jour}', String(jour));
  }
  return libelles.frequencies[frequence];
}
