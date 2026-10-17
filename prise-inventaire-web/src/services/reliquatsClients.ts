import { apiFetch } from '@/services/http';
import type { PageResultats } from '@/services/api';

/**
 * Reliquats clients (back orders) : produits qu'un client attend faute de stock, commandés au fournisseur, suivis à la
 * réception, puis facturés (module Ventes).
 */

export type StatutReliquat = 'cree' | 'commande' | 'recu_partiel' | 'recu' | 'facture' | 'annule';
export const STATUTS_RELIQUAT: StatutReliquat[] = ['cree', 'commande', 'recu_partiel', 'recu', 'facture', 'annule'];

export interface ReliquatClient {
  id: number;
  client_id: number;
  produit_id: number;
  quantite: number;
  quantite_commandee: number;
  quantite_recue: number;
  prix_unitaire_ht: string;
  remise_ligne: string;
  devise: string | null;
  commande_client_id: number | null;
  com_four_ligne_id: number | null;
  facture_id: number | null;
  date_requise: string | null;
  statut: StatutReliquat;
  notes: string | null;
  client?: { id: number; code: string; raison_sociale: string };
  produit?: { id: number; numero: string; description: string; mesure?: string | null };
  commande_client?: { id: number; numero: string } | null;
  ligne_commande_fournisseur?: { id: number; commande?: { id: number; numero: string; statut: string; fournisseur?: { id: number; raison_sociale: string } | null } | null } | null;
  facture?: { id: number; numero: string; statut: string } | null;
}

export type ResumeReliquats = Record<StatutReliquat, number>;

export interface LignesCommandeReliquat {
  commande: { id: number; numero: string; statut: string; client_id: number; devise: string | null; client: { id: number; code: string; raison_sociale: string } | null };
  possible: boolean;
  lignes: { ligne_id: number; produit: { id: number; numero: string; description: string } | null; quantite: number; deja_en_reliquat: number; prix_unitaire_ht: number }[];
}

export async function getReliquats(params: { statut?: string; client_id?: number; recherche?: string; page?: number } = {}): Promise<PageResultats<ReliquatClient> & { resume: ResumeReliquats }> {
  const query = new URLSearchParams();
  Object.entries(params).forEach(([cle, valeur]) => { if (valeur !== undefined && valeur !== '') query.set(cle, String(valeur)); });
  return apiFetch(`/reliquats-clients?${query.toString()}`);
}

export async function creerReliquat(donnees: { client_id: number; produit_id: number; quantite: number; prix_unitaire_ht: number; date_requise?: string | null; notes?: string | null }): Promise<ReliquatClient> {
  return apiFetch('/reliquats-clients', { method: 'POST', body: JSON.stringify(donnees), retries: 0 });
}

export async function getLignesCommandeReliquat(commandeId: number): Promise<LignesCommandeReliquat> {
  return apiFetch(`/reliquats-clients/depuis-commande/${commandeId}`);
}

export async function reliquatsDepuisCommande(commandeId: number, lignes: { ligne_id: number; quantite: number }[]): Promise<ReliquatClient[]> {
  return apiFetch(`/reliquats-clients/depuis-commande/${commandeId}`, { method: 'POST', body: JSON.stringify({ lignes }), retries: 0 });
}

export async function annulerReliquat(id: number): Promise<ReliquatClient> {
  return apiFetch(`/reliquats-clients/${id}/annuler`, { method: 'POST', retries: 0 });
}

export async function supprimerReliquat(id: number): Promise<void> {
  return apiFetch(`/reliquats-clients/${id}`, { method: 'DELETE', retries: 0 });
}

/** Commandes fournisseur brouillons des reliquats inscrits (fournisseur choisi, sinon le principal de chaque produit). */
export async function commanderReliquats(ids: number[], fournisseurId: number | null): Promise<{ id: number; numero: string }[]> {
  return apiFetch('/reliquats-clients/commander', { method: 'POST', body: JSON.stringify({ ids, fournisseur_id: fournisseurId }), retries: 0 });
}

/** Factures brouillons des reliquats reçus (une par client et par devise). */
export async function facturerReliquats(ids: number[], dateFacture: string): Promise<{ id: number; numero: string }[]> {
  return apiFetch('/reliquats-clients/facturer', { method: 'POST', body: JSON.stringify({ ids, date_facture: dateFacture }), retries: 0 });
}
