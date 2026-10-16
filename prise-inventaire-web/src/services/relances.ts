import { apiFetch } from '@/services/http';
import type { PageResultats } from '@/services/api';

/**
 * Relances de paiement (module Ventes) : factures en retard avec leurs relances, relance notée à la main
 * (téléphone, courrier…), historique. Les relances par courriel passent par l'envoi des courriels (type « relance »).
 */

export const MODES_RELANCE = ['courriel', 'telephone', 'courrier', 'autre'] as const;
export type ModeRelance = typeof MODES_RELANCE[number];

export interface FactureEnRetard {
  facture_id: number;
  numero: string;
  client: { id: number; code: string; raison_sociale: string; email: string | null } | null;
  date_facture: string;
  date_echeance: string | null;
  montant_ttc: number;
  reste_a_payer: number;
  retard: number;
  nb_relances: number;
  derniere_relance: { date: string; mode: ModeRelance; niveau: number; automatique: boolean } | null;
}

export interface ListeRelances {
  date: string;
  factures: FactureEnRetard[];
  totaux: { factures: number; clients: number; reste_a_payer: number; jamais_relancees: number };
}

export interface Relance {
  id: number;
  facture_id: number;
  client_id: number;
  niveau: number;
  date_relance: string;
  mode: ModeRelance;
  envoi_id: number | null;
  automatique: boolean;
  notes: string | null;
  auteur?: { id: number; nom: string } | null;
  facture?: { id: number; numero: string };
  client?: { id: number; code: string; raison_sociale: string };
}

export async function getFacturesEnRetard(params: { retard_min?: number; jamais?: boolean; recherche?: string; client_id?: number } = {}): Promise<ListeRelances> {
  const query = new URLSearchParams();
  if (params.retard_min) query.set('retard_min', String(params.retard_min));
  if (params.jamais) query.set('jamais', '1');
  if (params.recherche) query.set('recherche', params.recherche);
  if (params.client_id) query.set('client_id', String(params.client_id));
  return apiFetch(`/relances?${query.toString()}`);
}

export async function getRelancesFacture(factureId: number): Promise<Relance[]> {
  return apiFetch(`/factures/${factureId}/relances`);
}

export async function noterRelance(factureId: number, donnees: { mode: ModeRelance; date: string; notes?: string }): Promise<Relance> {
  return apiFetch(`/factures/${factureId}/relances`, { method: 'POST', body: JSON.stringify(donnees), retries: 0 });
}

export async function getHistoriqueRelances(params: { client_id?: number; page?: number } = {}): Promise<PageResultats<Relance>> {
  const query = new URLSearchParams();
  if (params.client_id) query.set('client_id', String(params.client_id));
  if (params.page) query.set('page', String(params.page));
  return apiFetch(`/relances/historique?${query.toString()}`);
}
