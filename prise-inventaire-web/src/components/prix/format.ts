import { useEffect, useState } from 'react';
import { getParametres } from '@/services/api';

const locale = (langue: string) => (langue === 'en' ? 'en-US' : 'fr-FR');

/** Prix avec le symbole de devise de l'entreprise (jusqu'à 4 décimales, sans zéros inutiles au-delà de 2). */
export function formatPrix(valeur: number | null | undefined, symbole: string, langue: string): string {
  if (valeur === null || valeur === undefined) return '—';
  const nombre = new Intl.NumberFormat(locale(langue), { minimumFractionDigits: 2, maximumFractionDigits: 4 }).format(valeur);
  return `${nombre} ${symbole}`.trim();
}

export function formatDateCourte(valeur: string | null | undefined, langue: string): string {
  if (!valeur) return '—';
  return new Date(`${valeur.slice(0, 10)}T00:00:00`).toLocaleDateString(locale(langue));
}

let symboleEnCache: Promise<string> | null = null;

/** Symbole de devise des paramètres de l'entreprise, chargé une fois par session. */
export function useSymboleDevise(): string {
  const [symbole, setSymbole] = useState('');
  useEffect(() => {
    symboleEnCache ??= getParametres().then(p => p.devise_symbole || '').catch(() => {
      symboleEnCache = null;
      return '';
    });
    let actif = true;
    symboleEnCache.then(s => { if (actif) setSymbole(s); });
    return () => { actif = false; };
  }, []);
  return symbole;
}

/** Les administrateurs et responsables modifient les prix ; les autres consultent. */
export const peutModifierPrix = (role?: string) => role === 'admin' || role === 'manager';
