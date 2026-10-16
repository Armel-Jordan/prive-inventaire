import { useEffect, useState } from 'react';
import { getParametres } from '@/services/api';

const locale = (langue: string) => (langue === 'en' ? 'en-US' : 'fr-FR');

/** Montant avec le symbole de devise de l'entreprise (pas d'euro imposé). */
export function formatMontant(valeur: number, symbole: string, langue: string): string {
  const nombre = new Intl.NumberFormat(locale(langue), { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(valeur);
  return `${nombre} ${symbole}`.trim();
}

/** Date « AAAA-MM-JJ » (ou ISO complète) affichée selon la langue. */
export function formatDate(valeur: string | null | undefined, langue: string, avecHeure = false): string {
  if (!valeur) return '—';
  const date = valeur.length === 10 ? new Date(`${valeur}T00:00:00`) : new Date(valeur);
  return avecHeure
    ? date.toLocaleString(locale(langue), { dateStyle: 'short', timeStyle: 'short' })
    : date.toLocaleDateString(locale(langue));
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
