import { apiFetch } from '@/services/http';
import type { PageResultats } from '@/services/api';

/**
 * Notes de crédit (avoirs) : créditer une facture émise, puis imputer le crédit disponible sur une facture du
 * client ou le lui rembourser (module Ventes).
 */

export type StatutAvoir = 'brouillon' | 'emis' | 'solde';
export const STATUTS_AVOIR: StatutAvoir[] = ['brouillon', 'emis', 'solde'];
export const MODES_REMBOURSEMENT = ['virement', 'cheque', 'especes', 'carte', 'autre'] as const;
export type ModeRemboursement = typeof MODES_REMBOURSEMENT[number];

export interface ProduitDocument { id: number; numero: string | null; description: string | null; mesure?: string | null }

export interface AvoirLigne {
  id: number;
  facture_ligne_id: number;
  produit_id: number | null;
  produit?: ProduitDocument | null;
  quantite: number;
  prix_unitaire_ht: string;
  remise_ligne: string;
  taux_tva: string;
  montant_ht: string;
  montant_tva: string;
  montant_ttc: string;
}

export interface Avoir {
  id: number;
  numero: string | null;
  facture_id: number;
  client_id: number;
  date_avoir: string;
  statut: StatutAvoir;
  motif: string | null;
  notes: string | null;
  montant_ht: string;
  montant_tva: string;
  montant_ttc: string;
  montant_impute: string;
  montant_rembourse: string;
  credit_disponible: number;
  emis_le: string | null;
  /** Devise de la note (celle de sa facture ; null : celle de l'entreprise). */
  devise?: string | null;
  taux_change?: string;
  client?: { id: number; code: string; raison_sociale: string; email?: string | null };
  facture?: { id: number; numero: string; date_facture?: string; statut?: string; montant_ttc?: string; reste_a_payer?: string };
  lignes?: AvoirLigne[];
  imputations?: { id: number; facture_id: number; date_imputation: string; montant: string; facture?: { id: number; numero: string } }[];
  remboursements?: { id: number; date_remboursement: string; montant: string; mode: string | null; reference: string | null; notes: string | null }[];
  recapitulatif_taxes?: { nom: string; taux: number; base: number; montant: number }[];
  auteur?: { id: number; nom: string } | null;
  emetteur?: { id: number; nom: string } | null;
  /** Retour de marchandise dont la note est issue. */
  retour?: { id: number; numero: string | null } | null;
}

export interface LigneProposee {
  facture_ligne_id: number;
  produit_id: number | null;
  produit: ProduitDocument | null;
  quantite_facturee: number;
  /** Unité facturée (la caisse de 12) ; vide : l'unité de stock. */
  unite?: string | null;
  prix_unitaire_ht: number;
  remise_ligne: number;
  taux_tva: number;
  montant_ht: number;
  quantite_creditee: number;
  credite_ht: number;
  reste_ht: number;
  quantite_proposee: number;
}

export interface PropositionAvoir {
  facture: {
    id: number; numero: string; statut: string; client_id: number; date_facture: string; devise?: string | null;
    montant_ht: string; montant_tva: string; montant_ttc: string; montant_paye: string; montant_credite: string; reste_a_payer: string;
    client: { id: number; code: string; raison_sociale: string } | null;
  };
  creditable: boolean;
  deja_credite: { ht: number; tva: number; ttc: number };
  reste_ttc: number;
  lignes: LigneProposee[];
}

export interface SaisieAvoir {
  date_avoir: string;
  motif?: string | null;
  notes?: string | null;
  lignes: { facture_ligne_id: number; quantite: number; prix_unitaire_ht: number }[];
}

export interface FactureImputable {
  id: number;
  numero: string;
  date_facture: string;
  date_echeance: string | null;
  montant_ttc: string;
  reste_a_payer: string;
}

export async function getAvoirs(params: { statut?: string; client_id?: number; facture_id?: number; recherche?: string; page?: number } = {}): Promise<PageResultats<Avoir>> {
  const query = new URLSearchParams();
  Object.entries(params).forEach(([cle, valeur]) => { if (valeur !== undefined && valeur !== '') query.set(cle, String(valeur)); });
  return apiFetch(`/avoirs?${query.toString()}`);
}

export async function getAvoir(id: number): Promise<Avoir> {
  return apiFetch(`/avoirs/${id}`);
}

/** Ce qu'on peut encore créditer sur une facture. */
export async function getPropositionAvoir(factureId: number): Promise<PropositionAvoir> {
  return apiFetch(`/factures/${factureId}/avoir`);
}

export async function creerAvoir(factureId: number, saisie: SaisieAvoir): Promise<Avoir> {
  return apiFetch('/avoirs', { method: 'POST', body: JSON.stringify({ facture_id: factureId, ...saisie }), retries: 0 });
}

export async function modifierAvoir(id: number, saisie: SaisieAvoir): Promise<Avoir> {
  return apiFetch(`/avoirs/${id}`, { method: 'PUT', body: JSON.stringify(saisie), retries: 0 });
}

export async function emettreAvoir(id: number): Promise<Avoir> {
  return apiFetch(`/avoirs/${id}/emettre`, { method: 'POST', retries: 0 });
}

export async function supprimerAvoir(id: number): Promise<void> {
  return apiFetch(`/avoirs/${id}`, { method: 'DELETE', retries: 0 });
}

export async function getFacturesImputables(id: number): Promise<FactureImputable[]> {
  return apiFetch(`/avoirs/${id}/factures-imputables`);
}

export async function imputerAvoir(id: number, donnees: { facture_id: number; montant: number; date: string }): Promise<Avoir> {
  return apiFetch(`/avoirs/${id}/imputer`, { method: 'POST', body: JSON.stringify(donnees), retries: 0 });
}

export async function rembourserAvoir(id: number, donnees: { montant: number; date_remboursement: string; mode: ModeRemboursement; reference?: string; notes?: string }): Promise<Avoir> {
  return apiFetch(`/avoirs/${id}/rembourser`, { method: 'POST', body: JSON.stringify(donnees), retries: 0 });
}
