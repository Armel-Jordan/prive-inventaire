/** Aide d'un écran dans une langue (plan de parité fripv2, phase 6.6 : aide contextuelle). */
export interface AideEcran {
  /** À quoi sert l'écran : une ou deux phrases. */
  resume: string;
  /** Comment faire la tâche principale de l'écran, étape par étape. */
  etapes?: string[];
  /** Bon à savoir : règles, points d'attention, raccourcis. */
  astuces?: string[];
  /** Autres mots pour retrouver l'écran dans la recherche (synonymes, termes du métier). */
  motsCles?: string[];
}

/** Aide des écrans, par adresse (« /clients », « /clients/:id »…), en français et en anglais. */
export type AidesEcrans = Record<string, { fr: AideEcran; en: AideEcran }>;
