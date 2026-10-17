import { apiFetch } from '@/services/http';
import type { PageResultats } from '@/services/api';

/**
 * Consignation chez le client : bons de dépôt, relevé (solde par produit), reprise, facturation de la consommation,
 * historique, rapport (module Ventes).
 */

export interface BonDepot {
  id: number;
  numero: string;
  client_id: number;
  date_depot: string;
  notes: string | null;
  client?: { id: number; code: string; raison_sociale: string } | null;
  lignes_count?: number;
  lignes_sum_quantite?: number | string | null;
  lignes?: { id: number; produit_id: number; quantite: number; cout_unitaire: string | null; produit?: { id: number; numero: string; description: string } | null }[];
}

export interface SoldeConsigne {
  client_id: number;
  produit_id: number;
  quantite: number;
  cout_unitaire: number | null;
  valeur: number | null;
  dernier_depot: string | null;
  produit?: { id: number; numero: string; description: string } | null;
}

export interface ReleveConsignation {
  client: { id: number; code: string; raison_sociale: string; devise: string | null } | null;
  lignes: SoldeConsigne[];
  quantite: number;
  valeur: number;
}

export interface MouvementConsignation {
  id: number;
  type: 'depot' | 'retour' | 'consommation';
  quantite: number;
  date_mouvement: string;
  lieu_retour: string | null;
  note: string | null;
  produit: { id: number; numero: string; description: string } | null;
  consignation: { id: number; numero: string } | null;
  facture: { id: number; numero: string } | null;
}

export interface RapportConsignations {
  filtre: string;
  devise: string;
  clients: { client: { id: number; code: string; raison_sociale: string } | null; lignes: SoldeConsigne[]; quantite: number; valeur: number }[];
  quantite: number;
  valeur: number;
}

type Lignes = { produit_id: number; quantite: number }[];

export function getBonsDepot(params: { recherche?: string; client_id?: number; page?: number }) {
  const requete = new URLSearchParams();
  Object.entries(params).forEach(([cle, valeur]) => { if (valeur !== undefined && valeur !== '') requete.append(cle, String(valeur)); });
  return apiFetch<PageResultats<BonDepot>>(`/consignations?${requete}`);
}

export const creerBonDepot = (saisie: { client_id: number; date_depot: string; notes: string | null; lignes: Lignes }) =>
  apiFetch<BonDepot>('/consignations', { method: 'POST', body: JSON.stringify(saisie) });

export const getReleveConsignation = (clientId: number) => apiFetch<ReleveConsignation>(`/consignations/releve?client_id=${clientId}`);

export const reprendreConsignation = (clientId: number, lieuRetour: string, lignes: Lignes) =>
  apiFetch<{ message: string }>('/consignations/reprise', { method: 'POST', body: JSON.stringify({ client_id: clientId, lieu_retour: lieuRetour, lignes }) });

export const facturerConsommation = (clientId: number, lignes: Lignes, emettre: boolean) =>
  apiFetch<{ message: string; facture: { id: number; numero: string; statut: string; montant_ttc: string; devise: string | null } }>(
    '/consignations/facturer', { method: 'POST', body: JSON.stringify({ client_id: clientId, lignes, emettre }) });

export const getHistoriqueConsignation = (clientId: number, du: string, au: string) =>
  apiFetch<MouvementConsignation[]>(`/consignations/historique?client_id=${clientId}&du=${du}&au=${au}`);

export function getRapportConsignations(clientId: number | null, nuls: boolean) {
  const requete = new URLSearchParams({ nuls: nuls ? '1' : '0' });
  if (clientId) requete.append('client_id', String(clientId));
  return apiFetch<RapportConsignations>(`/consignations/rapport?${requete}`);
}

/** Chemin du rapport en PDF (pour ouvrirPdf). */
export function cheminRapportPdf(clientId: number | null, nuls: boolean) {
  const requete = new URLSearchParams({ nuls: nuls ? '1' : '0', format: 'pdf' });
  if (clientId) requete.append('client_id', String(clientId));
  return `/consignations/rapport?${requete}`;
}
