import { apiFetch } from '@/services/http';

/**
 * Codes-barres d'un produit : EAN-13, EAN-8, UPC-A, Code 128 ou interne, chacun pour une quantité du produit par
 * lecture (le carton de 12) ; un principal. Un scan d'un de ces codes compte pour le produit.
 */

export type TypeCodeBarre = 'ean13' | 'ean8' | 'upca' | 'code128' | 'interne';
export const TYPES_CODE_BARRE: TypeCodeBarre[] = ['ean13', 'ean8', 'upca', 'code128', 'interne'];

export interface CodeBarre {
  id: number;
  produit_id: number;
  code: string;
  type: TypeCodeBarre;
  /** Unités du produit comptées par lecture (chaîne décimale). */
  quantite: string;
  principal: boolean;
  notes?: string | null;
}

export interface SaisieCodeBarre {
  code: string;
  /** Vide : deviné d'après le code. */
  type?: TypeCodeBarre | null;
  quantite?: number;
  principal?: boolean;
  notes?: string | null;
}

export function getCodesBarres(produitId: number): Promise<CodeBarre[]> {
  return apiFetch(`/produits/${produitId}/codes-barres`);
}

export function ajouterCodeBarre(produitId: number, saisie: SaisieCodeBarre): Promise<CodeBarre> {
  return apiFetch(`/produits/${produitId}/codes-barres`, { method: 'POST', body: JSON.stringify(saisie), retries: 0 });
}

export function modifierCodeBarre(produitId: number, id: number, saisie: SaisieCodeBarre): Promise<CodeBarre> {
  return apiFetch(`/produits/${produitId}/codes-barres/${id}`, { method: 'PUT', body: JSON.stringify(saisie), retries: 0 });
}

export function supprimerCodeBarre(produitId: number, id: number): Promise<{ message: string }> {
  return apiFetch(`/produits/${produitId}/codes-barres/${id}`, { method: 'DELETE', retries: 0 });
}
