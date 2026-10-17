import { apiFetch } from '@/services/http';
import type { PageResultats } from '@/services/api';

/**
 * Encaissements des clients (module Ventes) : l'argent reçu d'un client en une fois (chèque, virement…), réparti sur
 * plusieurs de ses factures dues ; le surplus reste à son crédit (acompte), une différence en sa faveur peut être
 * accordée en escompte. Un encaissement se corrige (référence, notes) ou s'annule.
 */

export const MODES_ENCAISSEMENT = ['cheque', 'virement', 'especes', 'carte', 'autre'] as const;
export type ModeEncaissement = typeof MODES_ENCAISSEMENT[number];
export type StatutEncaissement = 'valide' | 'annule';

export interface LigneEncaissement {
  id: number;
  facture_id: number;
  /** Montant porté sur la facture (argent et escompte). */
  montant: string;
  escompte: string;
  facture?: { id: number; numero: string; date_facture?: string; date_echeance?: string; montant_ttc?: string; reste_a_payer?: string; statut?: string };
}

export interface Encaissement {
  id: number;
  numero: string;
  client_id: number;
  client?: { id: number; code: string; raison_sociale: string };
  date_encaissement: string;
  mode: ModeEncaissement;
  reference: string | null;
  /** Vide : devise de l'entreprise. */
  devise: string | null;
  taux_change: string;
  montant: string;
  montant_applique: string;
  escompte: string;
  surplus: string;
  /** Acompte créé avec le surplus (vide une fois l'encaissement annulé). */
  acompte_id: number | null;
  acompte?: { id: number; montant: string; montant_applique: string; montant_rembourse: string; disponible: number } | null;
  caisse_session_id: number | null;
  compte_bancaire_id?: number | null;
  compte_bancaire?: { id: number; nom: string; banque: string } | null;
  notes: string | null;
  statut: StatutEncaissement;
  auteur?: { id: number; nom: string } | null;
  annule_le: string | null;
  auteur_annulation?: { id: number; nom: string } | null;
  motif_annulation: string | null;
  lignes?: LigneEncaissement[];
}

export interface ResumeEncaissements {
  nombre: number;
  montant: number;
  montant_applique: number;
  escompte: number;
  surplus: number;
}

export interface FiltresEncaissements {
  recherche?: string;
  client_id?: number;
  mode?: ModeEncaissement | '';
  statut?: StatutEncaissement | '';
  date_debut?: string;
  date_fin?: string;
  page?: number;
}

/** Facture due proposée à l'encaissement. */
export interface FactureARegler {
  id: number;
  numero: string;
  date_facture: string;
  date_echeance: string | null;
  jours_retard: number;
  montant_ttc: string;
  montant_paye: string;
  reste_a_payer: string;
  statut: string;
  devise: string | null;
}

export interface ARegler {
  devise: string | null;
  factures: FactureARegler[];
  total_du: number;
  credits: { acomptes: number; avoirs: number };
}

export interface SaisieEncaissement {
  client_id: number;
  date_encaissement: string;
  mode: ModeEncaissement;
  reference?: string | null;
  montant: number;
  devise?: string;
  taux_change?: number;
  notes?: string | null;
  /** Accorder en escompte ce que la répartition dépasse du montant reçu. */
  escompte?: boolean;
  /** Caisse du poste : l'encaissement entre dans sa session ouverte. */
  caisse_id?: number;
  /** Compte bancaire par lequel passe l'argent (chèque, virement, carte). */
  compte_bancaire_id?: number;
  lignes: { facture_id: number; montant: number }[];
}

export function getEncaissements(filtres: FiltresEncaissements = {}): Promise<PageResultats<Encaissement> & { resume: ResumeEncaissements }> {
  const parametres = new URLSearchParams();
  Object.entries(filtres).forEach(([cle, valeur]) => {
    if (valeur !== undefined && valeur !== null && valeur !== '') parametres.set(cle, String(valeur));
  });
  return apiFetch(`/encaissements?${parametres}`);
}

export function getEncaissement(id: number): Promise<Encaissement> {
  return apiFetch(`/encaissements/${id}`);
}

export function getARegler(clientId: number, devise?: string): Promise<ARegler> {
  const parametres = new URLSearchParams({ client_id: String(clientId) });
  if (devise) parametres.set('devise', devise);
  return apiFetch(`/encaissements/a-regler?${parametres}`);
}

export function creerEncaissement(saisie: SaisieEncaissement): Promise<Encaissement> {
  return apiFetch('/encaissements', { method: 'POST', body: JSON.stringify(saisie), retries: 0 });
}

export function modifierEncaissement(id: number, donnees: { reference?: string | null; notes?: string | null }): Promise<Encaissement> {
  return apiFetch(`/encaissements/${id}`, { method: 'PUT', body: JSON.stringify(donnees), retries: 0 });
}

export function annulerEncaissement(id: number, motif?: string | null): Promise<Encaissement> {
  return apiFetch(`/encaissements/${id}/annuler`, { method: 'POST', body: JSON.stringify({ motif: motif || null }), retries: 0 });
}

/**
 * Répartit un montant sur les factures dans leur ordre (l'échéance la plus ancienne d'abord) : chacune reçoit au plus
 * son reste ; ce qui dépasse leur total reste à l'écart (surplus).
 */
export function repartirMontant(montant: number, factures: Pick<FactureARegler, 'id' | 'reste_a_payer'>[]): Record<number, string> {
  const parts: Record<number, string> = {};
  let reste = Math.round(montant * 100);
  for (const facture of factures) {
    const part = Math.max(0, Math.min(reste, Math.round(Number(facture.reste_a_payer) * 100)));
    parts[facture.id] = part > 0 ? (part / 100).toFixed(2) : '';
    reste -= part;
  }
  return parts;
}
