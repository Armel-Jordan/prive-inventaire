import { apiFetch } from '@/services/http';

/** Comptes clients : analyse chronologique des sommes dues et relevé de compte d'un client (module Ventes). */

export type Tranche = 'non_echu' | 'jours_1_30' | 'jours_31_60' | 'jours_61_90' | 'plus_90';
export const TRANCHES: Tranche[] = ['non_echu', 'jours_1_30', 'jours_31_60', 'jours_61_90', 'plus_90'];

export interface CompteClient extends Record<Tranche, number> {
  client_id: number;
  code: string;
  raison_sociale: string;
  total: number;
  /** Crédits des notes de crédit, ni imputés ni remboursés : dus au client. */
  credits: number;
  /** Total dû moins les crédits. */
  solde: number;
  nb_factures: number;
  retard_max: number;
}

export interface AnalyseComptesClients {
  date: string;
  clients: CompteClient[];
  totaux: Record<Tranche | 'total' | 'credits' | 'solde', number>;
}

export interface AcompteDisponible {
  acompte_id: number;
  date_acompte: string;
  mode: string;
  reference: string | null;
  montant: number;
  disponible: number;
}

export interface CreditDisponible {
  avoir_id: number;
  numero: string;
  date_avoir: string;
  montant: number;
  disponible: number;
}

export interface FactureDue {
  facture_id: number;
  numero: string;
  date_facture: string;
  date_echeance: string | null;
  montant: number;
  reste: number;
  retard: number;
  tranche: Tranche;
}

export interface ReleveClient {
  client: { id: number; code: string; raison_sociale: string };
  du: string;
  au: string;
  solde_ouverture: number;
  lignes: { date: string; type: 'facture' | 'avoir' | 'acompte' | 'paiement' | 'escompte' | 'remboursement'; piece: string | null; libelle: string; debit: number; credit: number; solde: number }[];
  total_debit: number;
  total_credit: number;
  solde_final: number;
  factures_dues: FactureDue[];
  credits_disponibles: CreditDisponible[];
  acomptes_disponibles: AcompteDisponible[];
}

function requete(parametres: Record<string, string | undefined>): string {
  const recherche = new URLSearchParams();
  Object.entries(parametres).forEach(([cle, valeur]) => { if (valeur) recherche.set(cle, valeur); });
  return recherche.toString();
}

async function telecharger(chemin: string, nom: string): Promise<void> {
  const fichier = await apiFetch<Blob>(chemin, { responseType: 'blob', timeoutMs: 120000 });
  const lien = document.createElement('a');
  lien.href = URL.createObjectURL(fichier);
  lien.download = nom;
  lien.click();
  setTimeout(() => URL.revokeObjectURL(lien.href), 1000);
}

export async function getAnalyseComptesClients(date?: string): Promise<AnalyseComptesClients> {
  return apiFetch(`/comptes-clients?${requete({ date })}`);
}

export async function exporterAnalyseComptesClients(date: string): Promise<void> {
  return telecharger(`/comptes-clients?${requete({ date, format: 'csv' })}`, `comptes-clients-${date}.csv`);
}

export async function getReleveClient(clientId: number, du?: string, au?: string): Promise<ReleveClient> {
  return apiFetch(`/comptes-clients/${clientId}/releve?${requete({ du, au })}`);
}

export async function exporterReleveClient(clientId: number, code: string, du: string, au: string): Promise<void> {
  return telecharger(`/comptes-clients/${clientId}/releve?${requete({ du, au, format: 'csv' })}`, `releve-${code}-${du}-${au}.csv`);
}
