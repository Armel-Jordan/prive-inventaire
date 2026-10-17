import { apiFetch } from '@/services/http';
import type { NatureCompte } from '@/services/comptabilite';

/**
 * Budgets (module Finance) : le budget de chaque compte budgétable, période par période d'un exercice ; saisie par
 * cellules, répartition d'un total, copie d'un autre exercice, comptes du budget ; budget et réel.
 */

export interface ExerciceBudget {
  id: number;
  libelle: string;
  date_debut: string;
  date_fin: string;
  statut: string;
}

export interface PeriodeBudget {
  id: number;
  numero: number;
  date_debut: string;
  date_fin: string;
  fermee: boolean;
}

export interface LigneBudget {
  id: number;
  numero: string;
  libelle: string;
  nature: NatureCompte;
  actif: boolean;
  budgetable: boolean;
  /** Montant par période (identifiant de la période), dans le sens naturel du compte. */
  montants: Record<string, number>;
  total: number;
  /** Total budgété de l'exercice précédent, pour repère. */
  total_precedent: number | null;
}

export interface TotauxBudget {
  produits: number;
  charges: number;
  resultat: number;
}

export interface FeuilleBudget {
  exercice: ExerciceBudget | null;
  /** Faux pour un exercice clôturé : son budget ne se modifie plus. */
  modifiable?: boolean;
  precedent?: { id: number; libelle: string } | null;
  periodes?: PeriodeBudget[];
  comptes?: LigneBudget[];
  totaux?: TotauxBudget & { par_periode: Record<string, TotauxBudget> };
}

export interface CelluleBudget {
  compte_id: number;
  periode_id: number;
  /** Vide ou nul : la cellule est retirée. */
  montant: number | null;
}

export interface LigneComparaison {
  id: number;
  numero: string;
  libelle: string;
  nature: NatureCompte;
  budget: number;
  reel: number;
  ecart: number;
  /** Réalisé en % du budget ; null sans budget. */
  taux: number | null;
  /** Charge sous son budget ou produit au-dessus ; null pour les autres natures. */
  favorable: boolean | null;
}

export interface TotalComparaison {
  budget: number;
  reel: number;
  ecart: number;
}

export interface ComparaisonBudget {
  exercices: ExerciceBudget[];
  exercice: ExerciceBudget;
  periode_du: number;
  periode_au: number;
  du: string;
  au: string;
  lignes: LigneComparaison[];
  /** Produits et charges réels des comptes hors budget. */
  hors_budget: { produits: number; charges: number };
  totaux: { produits: TotalComparaison; charges: TotalComparaison; resultat: TotalComparaison };
}

function parametres(filtres: object): URLSearchParams {
  const p = new URLSearchParams();
  Object.entries(filtres).forEach(([cle, valeur]) => {
    if (valeur !== undefined && valeur !== null && valeur !== '') p.set(cle, String(valeur));
  });
  return p;
}

/** Feuille d'un exercice (par défaut celui du jour, sinon le plus récent) et la liste des exercices. */
export function getFeuilleBudget(exerciceId?: number): Promise<FeuilleBudget & { exercices: ExerciceBudget[] }> {
  return apiFetch(`/budgets?${parametres({ exercice_id: exerciceId })}`);
}

export function enregistrerCellulesBudget(exerciceId: number, cellules: CelluleBudget[]): Promise<FeuilleBudget> {
  return apiFetch('/budgets', { method: 'PUT', body: JSON.stringify({ exercice_id: exerciceId, cellules }), retries: 0 });
}

/** Répartit un total d'un compte également entre les périodes (le reste au centime sur la dernière). */
export function repartirBudget(exerciceId: number, compteId: number, total: number): Promise<FeuilleBudget> {
  return apiFetch('/budgets/repartir', { method: 'POST', body: JSON.stringify({ exercice_id: exerciceId, compte_id: compteId, total }), retries: 0 });
}

/** Copie le budget d'un autre exercice, ajusté d'un pourcentage ; remplacer, ou ne remplir que les cellules vides. */
export function copierBudget(exerciceId: number, sourceId: number, pourcentage: number, remplacer: boolean): Promise<FeuilleBudget & { message: string }> {
  return apiFetch('/budgets/copier', {
    method: 'POST', body: JSON.stringify({ exercice_id: exerciceId, source_exercice_id: sourceId, pourcentage, remplacer }), retries: 0,
  });
}

/** Ajoute des comptes de détail au budget, ou les en retire. */
export function marquerComptesBudget(comptes: number[], budgetable: boolean): Promise<{ message: string; modifies: number }> {
  return apiFetch('/budgets/comptes', { method: 'PUT', body: JSON.stringify({ comptes, budgetable }), retries: 0 });
}

export function getComparaisonBudget(exerciceId?: number, periodeDu?: number, periodeAu?: number): Promise<ComparaisonBudget> {
  return apiFetch(`/budgets/comparaison?${parametres({ exercice_id: exerciceId, periode_du: periodeDu, periode_au: periodeAu })}`);
}

/** Budget et réel en CSV, téléchargé avec l'en-tête d'authentification. */
export async function telechargerComparaisonBudget(exerciceId: number, periodeDu: number, periodeAu: number, nomFichier: string): Promise<void> {
  const fichier = await apiFetch<Blob>(`/budgets/comparaison?${parametres({ exercice_id: exerciceId, periode_du: periodeDu, periode_au: periodeAu, format: 'csv' })}`, { responseType: 'blob' });
  const lien = document.createElement('a');
  lien.href = URL.createObjectURL(fichier);
  lien.download = nomFichier;
  lien.click();
  setTimeout(() => URL.revokeObjectURL(lien.href), 1000);
}
