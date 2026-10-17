import { apiFetch } from '@/services/http';
import { ouvrirPdf, type PageResultats } from '@/services/api';
import type { ModeCaisse } from '@/services/caisses';

/**
 * Cartes cadeaux : liste, fiche et mouvements, vente, recharge, remboursement du solde, certificat ; consultation d'une
 * carte présentée pour payer (module Ventes).
 */

export type EtatCarte = 'active' | 'epuisee' | 'expiree' | 'annulee';
export const ETATS_CARTE: EtatCarte[] = ['active', 'epuisee', 'expiree', 'annulee'];

export interface MouvementCarte {
  id: number;
  type: 'vente' | 'recharge' | 'utilisation' | 'annulation_utilisation' | 'remboursement';
  montant: string | number;
  solde_apres: string | number;
  mode: ModeCaisse | null;
  reference: string | null;
  facture: { id: number; numero: string } | null;
  auteur: { id: number; nom: string } | null;
  created_at: string;
}

export interface CarteCadeau {
  id: number;
  numero: string;
  client_id: number | null;
  client: { id: number; code: string; raison_sociale: string } | null;
  devise: string | null;
  montant_initial: string | number;
  solde: string | number;
  date_vente: string;
  expire_le: string | null;
  annulee: boolean;
  notes: string | null;
  etat: EtatCarte;
  mouvements_count?: number;
  mouvements?: MouvementCarte[];
}

/** Carte présentée pour payer : son solde et son état. */
export interface CarteConsultee {
  id: number;
  numero: string;
  devise: string | null;
  solde: string | number;
  expire_le: string | null;
  etat: EtatCarte;
}

const requete = (params: Record<string, string | number | undefined>) =>
  new URLSearchParams(Object.entries(params).filter(([, v]) => v !== undefined && v !== '').map(([k, v]) => [k, String(v)]));

export function getCartesCadeaux(params: { recherche?: string; etat?: EtatCarte | ''; page?: number }): Promise<PageResultats<CarteCadeau> & { totaux: { a_honorer: number } }> {
  return apiFetch(`/cartes-cadeaux?${requete(params)}`);
}

export function getCarteCadeau(id: number): Promise<CarteCadeau> {
  return apiFetch(`/cartes-cadeaux/${id}`);
}

export function vendreCarteCadeau(donnees: {
  numero?: string;
  client_id?: number;
  devise?: string;
  montant: number;
  mode: ModeCaisse;
  reference?: string;
  date: string;
  expire_le?: string;
  notes?: string;
  caisse_id?: number;
}): Promise<CarteCadeau> {
  return apiFetch('/cartes-cadeaux', { method: 'POST', body: JSON.stringify(donnees), retries: 0 });
}

export function modifierCarteCadeau(id: number, donnees: { client_id?: number | null; expire_le?: string | null; notes?: string | null }): Promise<CarteCadeau> {
  return apiFetch(`/cartes-cadeaux/${id}`, { method: 'PUT', body: JSON.stringify(donnees), retries: 0 });
}

export function rechargerCarteCadeau(id: number, donnees: { montant: number; mode: ModeCaisse; reference?: string; caisse_id?: number }): Promise<CarteCadeau> {
  return apiFetch(`/cartes-cadeaux/${id}/recharger`, { method: 'POST', body: JSON.stringify(donnees), retries: 0 });
}

export function rembourserCarteCadeau(id: number, donnees: { mode: ModeCaisse; reference?: string; caisse_id?: number }): Promise<CarteCadeau> {
  return apiFetch(`/cartes-cadeaux/${id}/rembourser`, { method: 'POST', body: JSON.stringify(donnees), retries: 0 });
}

export function imprimerCertificat(carte: { id: number; numero: string }): Promise<void> {
  return ouvrirPdf(`/cartes-cadeaux/${carte.id}/certificat`, `certificat-${carte.numero}.pdf`);
}

/** Carte présentée pour payer (point de vente, encaissement d'une facture). */
export function consulterCarteCadeau(numero: string): Promise<CarteConsultee> {
  return apiFetch(`/point-de-vente/carte-cadeau?${new URLSearchParams({ numero })}`);
}
