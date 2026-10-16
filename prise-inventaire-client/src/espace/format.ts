import type { Language } from '@/i18n/translations';

const locale = (langue: Language) => (langue === 'en' ? 'en-US' : 'fr-FR');

/** Montant avec la devise de l'entreprise, deux décimales comme sur les documents. */
export function montant(valeur: number | string | null | undefined, langue: Language, devise?: string | null): string {
  const texte = Number(valeur ?? 0).toLocaleString(locale(langue), { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return devise ? `${texte} ${devise}` : texte;
}

/** Jour d'une date de l'API (AAAA-MM-JJ, suivie ou non de l'heure), sans décalage de fuseau. */
export function jour(iso: string | null | undefined, langue: Language): string {
  if (!iso) return '';
  const [annee, mois, j] = iso.slice(0, 10).split('-').map(Number);
  return new Date(annee, mois - 1, j).toLocaleDateString(locale(langue), { day: 'numeric', month: 'short', year: 'numeric' });
}

/** Date du jour au format des champs de date (AAAA-MM-JJ). */
export function aujourdhui(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** Remplace {cle} dans un texte traduit. */
export function remplir(texte: string, valeurs: Record<string, string | number>): string {
  return texte.replace(/\{(\w+)\}/g, (tout, cle: string) => (cle in valeurs ? String(valeurs[cle]) : tout));
}
