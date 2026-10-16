import { apiFetch } from '@/services/http';
import type { PageResultats } from '@/services/api';

/**
 * Acomptes et demandes d'acompte (module Ventes) : paiements reçus d'un client avant sa facture, appliqués au
 * paiement de ses factures ou remboursés ; demandes de dépôt envoyées au client, qui créent l'acompte une fois payées.
 */

export const MODES_ACOMPTE = ['virement', 'cheque', 'especes', 'carte', 'autre'] as const;
export type ModeAcompte = typeof MODES_ACOMPTE[number];
export type EtatAcompte = 'disponible' | 'entame' | 'utilise';

export interface Acompte {
  id: number;
  client_id: number;
  date_acompte: string;
  mode: ModeAcompte;
  reference: string | null;
  montant: string;
  commande_id: number | null;
  demande_id: number | null;
  notes: string | null;
  montant_applique: string;
  montant_rembourse: string;
  disponible: number;
  etat: EtatAcompte;
  /** Devise de l'acompte (null : celle de l'entreprise). */
  devise?: string | null;
  taux_change?: string;
  client?: { id: number; code: string; raison_sociale: string };
  commande?: { id: number; numero: string; statut?: string } | null;
  demande?: { id: number; numero: string } | null;
  applications?: { id: number; facture_id: number; date_paiement: string; montant: string; facture?: { id: number; numero: string } }[];
  remboursements?: { id: number; date_remboursement: string; montant: string; mode: string | null; reference: string | null }[];
  auteur?: { id: number; nom: string } | null;
}

export interface ListeAcomptes extends PageResultats<Acompte> {
  totaux: { montant: number; disponible: number };
}

export interface SaisieAcompte {
  client_id: number;
  date_acompte: string;
  mode: ModeAcompte;
  reference?: string | null;
  montant: number;
  commande_id?: number | null;
  notes?: string | null;
  devise?: string;
  taux_change?: number;
}

export type StatutDemande = 'brouillon' | 'envoyee' | 'payee';
export const STATUTS_DEMANDE: StatutDemande[] = ['brouillon', 'envoyee', 'payee'];
export type EtatDemande = StatutDemande | 'partiellement_appliquee' | 'appliquee';

export interface DemandeAcompte {
  id: number;
  numero: string;
  client_id: number;
  date_demande: string;
  description: string;
  montant: string;
  statut: StatutDemande;
  etat: EtatDemande;
  devise?: string | null;
  taux_change?: string;
  devis_id: number | null;
  commande_id: number | null;
  acompte_id: number | null;
  envoyee_le: string | null;
  payee_le: string | null;
  client?: { id: number; code: string; raison_sociale: string; email?: string | null };
  devis?: { id: number; numero: string } | null;
  commande?: { id: number; numero: string } | null;
  acompte?: (Partial<Acompte> & { id: number }) | null;
}

export interface SaisieDemande {
  client_id: number;
  date_demande: string;
  description: string;
  montant: number;
  devis_id?: number | null;
  commande_id?: number | null;
  devise?: string;
  taux_change?: number;
}

const requete = (parametres: Record<string, string | number | undefined>) => {
  const query = new URLSearchParams();
  Object.entries(parametres).forEach(([cle, valeur]) => { if (valeur !== undefined && valeur !== '') query.set(cle, String(valeur)); });
  return query.toString();
};

export async function getAcomptes(params: { client_id?: number; commande_id?: number; disponibilite?: 'avec_solde' | 'utilise'; recherche?: string; page?: number; per_page?: number } = {}): Promise<ListeAcomptes> {
  return apiFetch(`/acomptes?${requete(params)}`);
}

export async function getAcompte(id: number): Promise<Acompte> {
  return apiFetch(`/acomptes/${id}`);
}

export async function creerAcompte(saisie: SaisieAcompte): Promise<Acompte> {
  return apiFetch('/acomptes', { method: 'POST', body: JSON.stringify(saisie), retries: 0 });
}

export async function modifierAcompte(id: number, saisie: Partial<SaisieAcompte>): Promise<Acompte> {
  return apiFetch(`/acomptes/${id}`, { method: 'PUT', body: JSON.stringify(saisie), retries: 0 });
}

export async function supprimerAcompte(id: number): Promise<void> {
  return apiFetch(`/acomptes/${id}`, { method: 'DELETE', retries: 0 });
}

export async function appliquerAcompte(id: number, donnees: { facture_id: number; montant: number; date: string }): Promise<Acompte> {
  return apiFetch(`/acomptes/${id}/appliquer`, { method: 'POST', body: JSON.stringify(donnees), retries: 0 });
}

export async function rembourserAcompte(id: number, donnees: { montant: number; date_remboursement: string; mode?: ModeAcompte; reference?: string }): Promise<Acompte> {
  return apiFetch(`/acomptes/${id}/rembourser`, { method: 'POST', body: JSON.stringify(donnees), retries: 0 });
}

export async function getDemandesAcompte(params: { statut?: string; client_id?: number; recherche?: string; page?: number } = {}): Promise<PageResultats<DemandeAcompte>> {
  return apiFetch(`/demandes-acompte?${requete(params)}`);
}

export async function getDemandeAcompte(id: number): Promise<DemandeAcompte> {
  return apiFetch(`/demandes-acompte/${id}`);
}

export async function creerDemandeAcompte(saisie: SaisieDemande): Promise<DemandeAcompte> {
  return apiFetch('/demandes-acompte', { method: 'POST', body: JSON.stringify(saisie), retries: 0 });
}

export async function modifierDemandeAcompte(id: number, saisie: Partial<SaisieDemande>): Promise<DemandeAcompte> {
  return apiFetch(`/demandes-acompte/${id}`, { method: 'PUT', body: JSON.stringify(saisie), retries: 0 });
}

export async function supprimerDemandeAcompte(id: number): Promise<void> {
  return apiFetch(`/demandes-acompte/${id}`, { method: 'DELETE', retries: 0 });
}

export async function envoyerDemandeAcompte(id: number): Promise<DemandeAcompte> {
  return apiFetch(`/demandes-acompte/${id}/envoyer`, { method: 'POST', retries: 0 });
}

export async function encaisserDemandeAcompte(id: number, paiement: { date: string; mode: ModeAcompte; reference?: string }): Promise<DemandeAcompte> {
  return apiFetch(`/demandes-acompte/${id}/encaisser`, { method: 'POST', body: JSON.stringify(paiement), retries: 0 });
}

/** Devis d'un client (pour rattacher une demande d'acompte). */
export async function getDevisDuClient(clientId: number): Promise<{ id: number; numero: string; statut: string }[]> {
  const page = await apiFetch<PageResultats<{ id: number; numero: string; statut: string }>>(`/devis?${requete({ client_id: clientId, per_page: 50 })}`);
  return page.data ?? [];
}
