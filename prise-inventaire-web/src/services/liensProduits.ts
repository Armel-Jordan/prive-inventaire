import { apiFetch } from '@/services/http';

/**
 * Liens entre produits (phase 2.3) : composants d'un ensemble (kit qui se vend en un article et consomme ses
 * composants), annexes ajoutées d'office sur les lignes de vente, équivalents (substituts, dans les deux sens).
 */

export type TypeLien = 'composant' | 'annexe' | 'equivalent';

export interface LienProduit {
  id: number;
  type: TypeLien;
  lie_id: number;
  /** Composant ou annexe : quantité pour une unité du produit. */
  quantite: number;
  remarque: string | null;
  produit: { id: number; numero: string; description: string; mesure: string | null; actif: boolean; prix_unitaire: string | number | null } | null;
}

export interface LiensDuProduit {
  composants: LienProduit[];
  annexes: LienProduit[];
  equivalents: LienProduit[];
  /** Ensembles qui contiennent ce produit. */
  ensembles: { id: number; numero: string | null; description: string | null; quantite: number }[];
}

export function getLiensProduit(produitId: number): Promise<LiensDuProduit> {
  return apiFetch(`/produits/${produitId}/liens`);
}

export function ajouterLienProduit(produitId: number, saisie: { type: TypeLien; lie_id: number; quantite?: number; remarque?: string | null }): Promise<LienProduit> {
  return apiFetch(`/produits/${produitId}/liens`, { method: 'POST', body: JSON.stringify(saisie), retries: 0 });
}

export function modifierLienProduit(produitId: number, id: number, saisie: { quantite?: number; remarque?: string | null }): Promise<LienProduit> {
  return apiFetch(`/produits/${produitId}/liens/${id}`, { method: 'PUT', body: JSON.stringify(saisie), retries: 0 });
}

export function retirerLienProduit(produitId: number, id: number): Promise<{ message: string }> {
  return apiFetch(`/produits/${produitId}/liens/${id}`, { method: 'DELETE', retries: 0 });
}
