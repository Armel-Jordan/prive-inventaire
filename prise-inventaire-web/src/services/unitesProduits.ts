import { apiFetch } from '@/services/http';
import type { UniteVenteProduit } from '@/types';

/**
 * Unités de vente d'un produit (phase 2.2) : en plus de son unité de stock, les unités du référentiel dans lesquelles il
 * se vend (la caisse de 12), avec leur facteur (proposé d'après les conversions du référentiel) et leur prix propre.
 */

export interface UniteDisponible {
  id: number;
  code: string;
  libelle: string;
  decimales: number;
  /** Facteur d'après les conversions du référentiel ; null : aucune ne relie cette unité à l'unité de stock. */
  facteur_propose: number | null;
}

export interface UnitesDuProduit {
  /** Unité de stock du produit. */
  mesure: string | null;
  unites: UniteVenteProduit[];
  /** Unités du référentiel que le produit peut encore prendre. */
  disponibles: UniteDisponible[];
}

export interface SaisieUniteProduit {
  unite_id?: number;
  /** Vide : celui des conversions du référentiel. */
  facteur?: number | null;
  /** Vide : le prix du produit multiplié par le facteur. */
  prix?: number | null;
  par_defaut?: boolean;
}

export function getUnitesProduit(produitId: number): Promise<UnitesDuProduit> {
  return apiFetch(`/produits/${produitId}/unites`);
}

export function ajouterUniteProduit(produitId: number, saisie: SaisieUniteProduit): Promise<UniteVenteProduit> {
  return apiFetch(`/produits/${produitId}/unites`, { method: 'POST', body: JSON.stringify(saisie), retries: 0 });
}

export function modifierUniteProduit(produitId: number, id: number, saisie: SaisieUniteProduit): Promise<UniteVenteProduit> {
  return apiFetch(`/produits/${produitId}/unites/${id}`, { method: 'PUT', body: JSON.stringify(saisie), retries: 0 });
}

export function supprimerUniteProduit(produitId: number, id: number): Promise<{ message: string }> {
  return apiFetch(`/produits/${produitId}/unites/${id}`, { method: 'DELETE', retries: 0 });
}
