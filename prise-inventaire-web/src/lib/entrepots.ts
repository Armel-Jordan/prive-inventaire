import { getReferentiel } from '@/services/api';
import type { Secteur } from '@/types';

/** Entrepôt du référentiel (multi-entrepôts), pour les listes de choix et les libellés. */
export interface EntrepotChoix {
  id: number;
  code: string;
  nom: string;
  actif: boolean;
  par_defaut: boolean;
}

/** Entrepôts du référentiel, désactivés compris (libellés des secteurs qui y restent rangés), par code. */
export async function chargerEntrepots(): Promise<EntrepotChoix[]> {
  const liste = await getReferentiel('entrepots');
  return liste
    .map(e => ({ id: e.id, code: String(e.code ?? ''), nom: String(e.nom ?? ''), actif: e.actif !== false, par_defaut: e.par_defaut === true }))
    .sort((a, b) => a.code.localeCompare(b.code));
}

export const libelleEntrepot = (e: { code: string; nom: string }) => `${e.code} — ${e.nom}`;

/**
 * Secteurs groupés par entrepôt pour une liste de choix : l'entrepôt préféré d'abord (celui d'une commande à
 * recevoir), puis les autres par code, enfin les secteurs sans entrepôt. Sans aucun entrepôt : un seul groupe sans titre.
 */
export function secteursParEntrepot(secteurs: Secteur[], prefere?: number | null, sansEntrepot = 'Sans entrepôt'): { titre: string | null; secteurs: Secteur[] }[] {
  if (!secteurs.some(s => s.entrepot)) return [{ titre: null, secteurs }];
  const groupes = new Map<number | null, { titre: string | null; secteurs: Secteur[] }>();
  for (const secteur of secteurs) {
    const cle = secteur.entrepot?.id ?? null;
    const groupe = groupes.get(cle) ?? { titre: secteur.entrepot ? libelleEntrepot(secteur.entrepot) : sansEntrepot, secteurs: [] };
    groupe.secteurs.push(secteur);
    groupes.set(cle, groupe);
  }
  const rang = (cle: number | null) => (cle === null ? 2 : cle === prefere ? 0 : 1);
  return [...groupes.entries()]
    .sort(([a, ga], [b, gb]) => rang(a) - rang(b) || String(ga.titre).localeCompare(String(gb.titre)))
    .map(([, groupe]) => groupe);
}
