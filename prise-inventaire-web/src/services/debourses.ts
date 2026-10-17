import { apiFetch } from '@/services/http';
import type { PageResultats } from '@/services/api';

/**
 * Déboursés aux fournisseurs (module Achats) : l'argent versé à un fournisseur en une fois (chèque, virement…), réparti
 * sur plusieurs de ses factures, avec l'escompte qu'il accorde pour un paiement rapide. Un déboursé se corrige
 * (référence, notes) ou s'annule ; son avis de paiement s'imprime.
 */

export const MODES_DEBOURSE = ['cheque', 'virement', 'especes', 'carte', 'autre'] as const;
export type ModeDebourse = typeof MODES_DEBOURSE[number];
export type StatutDebourse = 'valide' | 'annule';

export interface LigneDebourse {
  id: number;
  facture_fournisseur_id: number;
  /** Montant porté sur la facture (argent et escompte). */
  montant: string;
  escompte: string;
  facture?: { id: number; numero: string; numero_fournisseur: string | null; date_facture?: string; montant_ttc?: string };
}

export interface Debourse {
  id: number;
  numero: string;
  fournisseur_id: number;
  fournisseur?: { id: number; code: string; raison_sociale: string };
  date_debourse: string;
  mode: ModeDebourse;
  reference: string | null;
  /** Vide : devise de l'entreprise. */
  devise: string | null;
  taux_change: string;
  /** Argent versé. */
  montant: string;
  montant_applique: string;
  escompte: string;
  notes: string | null;
  statut: StatutDebourse;
  auteur?: { id: number; nom: string } | null;
  annule_le: string | null;
  auteur_annulation?: { id: number; nom: string } | null;
  motif_annulation: string | null;
  lignes?: LigneDebourse[];
}

export interface ResumeDebourses {
  nombre: number;
  montant: number;
  escompte: number;
}

export interface FiltresDebourses {
  recherche?: string;
  fournisseur_id?: number;
  mode?: ModeDebourse | '';
  statut?: StatutDebourse | '';
  date_debut?: string;
  date_fin?: string;
  page?: number;
}

/** Escompte offert sur une facture pour un paiement à la date demandée. */
export interface EscompteOffert {
  applicable: boolean;
  condition: string;
  pourcentage: number;
  date_limite: string;
  escompte: number;
}

/** Facture fournisseur à payer proposée au déboursé. */
export interface FactureAPayer {
  id: number;
  numero: string;
  numero_fournisseur: string | null;
  date_facture: string;
  date_echeance: string | null;
  jours_retard: number;
  montant_ttc: number;
  montant_paye: number;
  reste_a_payer: number;
  escompte: EscompteOffert | null;
}

export interface APayer {
  devise: string | null;
  factures: FactureAPayer[];
  total_du: number;
}

export interface SaisieDebourse {
  fournisseur_id: number;
  date_debourse: string;
  mode: ModeDebourse;
  reference?: string | null;
  devise?: string;
  taux_change?: number;
  notes?: string | null;
  /** Par facture : l'argent versé et l'escompte obtenu. */
  lignes: { facture_fournisseur_id: number; montant: number; escompte: number }[];
}

export function getDebourses(filtres: FiltresDebourses = {}): Promise<PageResultats<Debourse> & { resume: ResumeDebourses }> {
  const parametres = new URLSearchParams();
  Object.entries(filtres).forEach(([cle, valeur]) => {
    if (valeur !== undefined && valeur !== null && valeur !== '') parametres.set(cle, String(valeur));
  });
  return apiFetch(`/debourses?${parametres}`);
}

export function getDebourse(id: number): Promise<Debourse> {
  return apiFetch(`/debourses/${id}`);
}

export function getAPayer(fournisseurId: number, date: string, devise?: string): Promise<APayer> {
  const parametres = new URLSearchParams({ fournisseur_id: String(fournisseurId), date });
  if (devise) parametres.set('devise', devise);
  return apiFetch(`/debourses/a-payer?${parametres}`);
}

export function creerDebourse(saisie: SaisieDebourse): Promise<Debourse> {
  return apiFetch('/debourses', { method: 'POST', body: JSON.stringify(saisie), retries: 0 });
}

export function modifierDebourse(id: number, donnees: { reference?: string | null; notes?: string | null }): Promise<Debourse> {
  return apiFetch(`/debourses/${id}`, { method: 'PUT', body: JSON.stringify(donnees), retries: 0 });
}

export function annulerDebourse(id: number, motif?: string | null): Promise<Debourse> {
  return apiFetch(`/debourses/${id}/annuler`, { method: 'POST', body: JSON.stringify({ motif: motif || null }), retries: 0 });
}

// === Paiements dus (plan de décaissement) ===

/** Facture encore due : solde à la date du rapport, escompte encore offert à la date « payer le », net à payer. */
export interface LignePaiementDu {
  id: number;
  numero: string;
  numero_fournisseur: string | null;
  fournisseur_id: number;
  fournisseur: string;
  devise: string | null;
  taux_change: number;
  date_facture: string;
  date_echeance: string;
  /** Retard à la date du rapport ; négatif : l'échéance est encore à venir. */
  jours: number;
  montant: number;
  regle: number;
  solde: number;
  limite_escompte: string | null;
  escompte: number;
  net: number;
}

export interface TotauxPaiementsDus {
  nombre: number;
  montant: number;
  regle: number;
  solde: number;
  escompte: number;
  net: number;
}

export interface GroupePaiementsDus {
  fournisseur_id: number;
  fournisseur: string;
  code_fournisseur: string;
  devise: string | null;
  factures: LignePaiementDu[];
  totaux: TotauxPaiementsDus;
}

export interface RapportPaiementsDus {
  date: string;
  payer_le: string;
  echeance_au: string;
  groupes: GroupePaiementsDus[];
  totaux_par_devise: (TotauxPaiementsDus & { devise: string | null })[];
  total_entreprise: { solde: number; escompte: number; net: number };
}

export interface FiltresPaiementsDus {
  date?: string;
  payer_le?: string;
  echeance_au?: string;
  fournisseur_id?: number;
  echus?: boolean;
  escompte_seulement?: boolean;
  inclure_soldees?: boolean;
}

function parametresPaiementsDus(filtres: FiltresPaiementsDus): URLSearchParams {
  const parametres = new URLSearchParams();
  Object.entries(filtres).forEach(([cle, valeur]) => {
    if (valeur === undefined || valeur === null || valeur === '' || valeur === false) return;
    parametres.set(cle, valeur === true ? '1' : String(valeur));
  });
  return parametres;
}

export function getPaiementsDus(filtres: FiltresPaiementsDus): Promise<RapportPaiementsDus> {
  return apiFetch(`/debourses/paiements-dus?${parametresPaiementsDus(filtres)}`);
}

export async function exporterPaiementsDus(filtres: FiltresPaiementsDus): Promise<void> {
  const parametres = parametresPaiementsDus(filtres);
  parametres.set('format', 'csv');
  const fichier = await apiFetch<Blob>(`/debourses/paiements-dus?${parametres}`, { responseType: 'blob', timeoutMs: 120000 });
  const lien = document.createElement('a');
  lien.href = URL.createObjectURL(fichier);
  lien.download = `paiements-dus-${filtres.date ?? ''}.csv`;
  lien.click();
  setTimeout(() => URL.revokeObjectURL(lien.href), 1000);
}

/** Paie une sélection de factures en un geste : un déboursé par fournisseur et par devise. */
export function payerSelection(donnees: {
  factures: number[];
  date_debourse: string;
  mode: ModeDebourse;
  reference?: string | null;
  notes?: string | null;
  /** Appliquer l'escompte encore offert à la date du paiement. */
  escompte?: boolean;
}): Promise<{ message: string; debourses: Debourse[] }> {
  return apiFetch('/debourses/lot', { method: 'POST', body: JSON.stringify(donnees), retries: 0 });
}
