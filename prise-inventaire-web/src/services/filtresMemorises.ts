import { apiFetch } from '@/services/http';

/** Filtres mémorisés (phase 5.3) : ceux du compte connecté, par écran. */

export type ValeurFiltre = string | number | boolean | null | (string | number | boolean | null)[];
export type ValeursFiltres = Record<string, ValeurFiltre>;

export interface FiltreMemorise {
  id: number;
  ecran: string;
  nom: string;
  /** Une valeur vide revient vide (null). */
  valeurs: ValeursFiltres;
  par_defaut: boolean;
  updated_at: string | null;
}

export function getFiltresMemorises(ecran: string): Promise<FiltreMemorise[]> {
  return apiFetch(`/filtres-memorises?ecran=${encodeURIComponent(ecran)}`);
}

/** Enregistre les filtres sous ce nom ; un filtre du même nom est remplacé. */
export function enregistrerFiltreMemorise(ecran: string, nom: string, valeurs: ValeursFiltres, parDefaut: boolean): Promise<FiltreMemorise> {
  return apiFetch('/filtres-memorises', { method: 'POST', body: JSON.stringify({ ecran, nom, valeurs, par_defaut: parDefaut }) });
}

export function modifierFiltreMemorise(id: number, donnees: { nom?: string; par_defaut?: boolean }): Promise<FiltreMemorise> {
  return apiFetch(`/filtres-memorises/${id}`, { method: 'PUT', body: JSON.stringify(donnees) });
}

export function supprimerFiltreMemorise(id: number): Promise<void> {
  return apiFetch(`/filtres-memorises/${id}`, { method: 'DELETE', responseType: 'void' });
}

/** Valeur de filtre en texte, pour un champ de saisie ou une liste (vide si absente). */
export const texteFiltre = (valeur: ValeurFiltre | undefined) => (valeur === null || valeur === undefined || Array.isArray(valeur) ? '' : String(valeur));
