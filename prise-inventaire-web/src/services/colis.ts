import { apiFetch } from '@/services/http';

/** Colis des bons de livraison (préparation des boîtes). */

export interface LigneColis { id: number; colis_id: number; bon_livraison_ligne_id: number; quantite: number }

export interface Colis {
  id: number;
  numero: string;
  poids_kg: string | null;
  notes: string | null;
  charge_le: string | null;
  lignes: LigneColis[];
}

export interface ColisDuBon {
  bon: { id: number; numero: string; statut: string; client: string | null };
  /** Le bon n'est pas encore parti en livraison : les colis se préparent et se modifient. */
  modifiable: boolean;
  lignes: { id: number; produit: { id: number; numero: string; description: string; mesure: string } | null; quantite_a_livrer: number; restant: number }[];
  colis: Colis[];
}

export interface SaisieColis {
  lignes: { bon_livraison_ligne_id: number; quantite: number }[];
  poids_kg?: number | null;
  notes?: string | null;
}

export const getColis = (bonId: number) => apiFetch<ColisDuBon>(`/bons-livraison/${bonId}/colis`);

export const creerColis = (bonId: number, saisie: SaisieColis) =>
  apiFetch<ColisDuBon>(`/bons-livraison/${bonId}/colis`, { method: 'POST', body: JSON.stringify(saisie) });

export const modifierColis = (bonId: number, colisId: number, saisie: SaisieColis) =>
  apiFetch<ColisDuBon>(`/bons-livraison/${bonId}/colis/${colisId}`, { method: 'PUT', body: JSON.stringify(saisie) });

export const supprimerColis = (bonId: number, colisId: number) =>
  apiFetch<ColisDuBon>(`/bons-livraison/${bonId}/colis/${colisId}`, { method: 'DELETE' });
