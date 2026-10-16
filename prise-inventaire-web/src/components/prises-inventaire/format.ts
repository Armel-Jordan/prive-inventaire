const locale = (langue: string) => (langue === 'en' ? 'en-US' : 'fr-FR');

/** Quantité lisible (jusqu'à 4 décimales, sans zéros inutiles). */
export function formatQuantite(valeur: number | null | undefined, langue: string): string {
  if (valeur === null || valeur === undefined) return '—';
  return new Intl.NumberFormat(locale(langue), { maximumFractionDigits: 4 }).format(valeur);
}

/** Écart signé (+3, −2), « — » s'il n'y a pas de référence. */
export function formatEcart(valeur: number | null | undefined, langue: string): string {
  if (valeur === null || valeur === undefined) return '—';
  const texte = formatQuantite(Math.abs(valeur), langue);
  return valeur > 0 ? `+${texte}` : valeur < 0 ? `−${texte}` : '0';
}

export function formatDateHeure(valeur: string | null | undefined, langue: string): string {
  if (!valeur) return '—';
  return new Date(valeur).toLocaleString(locale(langue), { dateStyle: 'short', timeStyle: 'short' });
}

/** Valeur pour un champ datetime-local (heure locale, à la minute). */
export function versDateHeureLocale(date: Date): string {
  const decalage = date.getTimezoneOffset() * 60000;
  return new Date(date.getTime() - decalage).toISOString().slice(0, 16);
}

export const couleursStatutPrise: Record<string, string> = {
  en_cours: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
  validee: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
  annulee: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
};

export const couleursStatutLigne: Record<string, string> = {
  nouveau: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
  non_compte: 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
  ecart: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
  conforme: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
};

/** Les administrateurs et responsables créent, corrigent, valident et annulent les prises. */
