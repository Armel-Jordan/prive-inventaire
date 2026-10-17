import { apiFetch } from '@/services/http';
import type { PageResultats } from '@/services/api';
import type { SourceEcriture } from '@/services/comptabilite';

/**
 * Banque (module Finance) : les comptes bancaires de l'entreprise reliés à leur compte comptable et à leur journal, leurs
 * soldes et leurs mouvements (toutes les écritures de leur compte comptable), et les transactions bancaires sans
 * facture : dépôts, retraits (frais, intérêts, dépenses), virements entre comptes.
 */

export interface CompteBanque {
  id: number;
  nom: string;
  banque: string;
  numero_compte: string | null;
  iban: string | null;
  /** Vide : devise de l'entreprise. */
  devise_code: string | null;
  par_defaut: boolean;
  actif: boolean;
  compte_comptable_id: number | null;
  journal_id: number | null;
  compte_comptable: { id: number; numero: string; libelle: string } | null;
  journal: { id: number; code: string; libelle: string } | null;
  /** Solde comptable à la date demandée ; null sans compte comptable relié. */
  solde: number | null;
}

export interface MouvementBanque {
  ecriture_id: number;
  date: string;
  numero: string;
  journal: string;
  libelle: string;
  reference: string | null;
  /** Origine de l'écriture (manuelle, encaissement, transaction bancaire…). */
  source: SourceEcriture;
  debit: number;
  credit: number;
  solde: number;
}

export interface MouvementsBanque {
  compte_bancaire: { id: number; nom: string; banque: string; devise_code: string | null };
  compte: { id: number; numero: string; libelle: string };
  du: string;
  au: string;
  solde_ouverture: number;
  lignes: MouvementBanque[];
  total_debit: number;
  total_credit: number;
  solde_final: number | null;
  tronque: boolean;
}

export type TypeTransactionBancaire = 'depot' | 'retrait' | 'virement';
export const TYPES_TRANSACTION_BANCAIRE: TypeTransactionBancaire[] = ['depot', 'retrait', 'virement'];

export interface TransactionBancaire {
  id: number;
  numero: string;
  compte_bancaire_id: number;
  compte_bancaire?: { id: number; nom: string; devise_code?: string | null };
  date_transaction: string;
  type: TypeTransactionBancaire;
  compte_bancaire_destination_id: number | null;
  destination?: { id: number; nom: string } | null;
  reference: string | null;
  libelle: string;
  devise: string | null;
  taux_change: string;
  montant: string;
  notes: string | null;
  statut: 'valide' | 'annule';
  lignes?: { id: number; compte_comptable_id: number; libelle: string | null; montant: string; compte?: { id: number; numero: string; libelle: string } }[];
  auteur?: { id: number; nom: string } | null;
  annule_le: string | null;
  auteur_annulation?: { id: number; nom: string } | null;
  motif_annulation: string | null;
}

export interface FiltresTransactionsBancaires {
  compte_bancaire_id?: number;
  type?: TypeTransactionBancaire | '';
  statut?: 'valide' | 'annule' | '';
  date_debut?: string;
  date_fin?: string;
  recherche?: string;
  page?: number;
}

export interface SaisieTransactionBancaire {
  compte_bancaire_id: number;
  date_transaction: string;
  type: TypeTransactionBancaire;
  compte_bancaire_destination_id?: number | null;
  reference?: string | null;
  libelle: string;
  /** Virement : le montant viré ; dépôt ou retrait : le total des lignes (contrôlé). */
  montant?: number;
  taux_change?: number;
  notes?: string | null;
  lignes?: { compte_comptable_id: number; libelle?: string | null; montant: number }[];
}

function parametres(filtres: object): URLSearchParams {
  const p = new URLSearchParams();
  Object.entries(filtres).forEach(([cle, valeur]) => {
    if (valeur !== undefined && valeur !== null && valeur !== '') p.set(cle, String(valeur));
  });
  return p;
}

export function getComptesBanque(date?: string): Promise<{ date: string; comptes: CompteBanque[] }> {
  return apiFetch(`/banque/comptes?${parametres({ date })}`);
}

export function relierCompteBancaire(id: number, donnees: { compte_comptable_id: number | null; journal_id: number | null }): Promise<CompteBanque> {
  return apiFetch(`/banque/comptes/${id}`, { method: 'PUT', body: JSON.stringify(donnees), retries: 0 });
}

export function getMouvementsBanque(id: number, du: string, au: string, recherche?: string): Promise<MouvementsBanque> {
  return apiFetch(`/banque/comptes/${id}/mouvements?${parametres({ du, au, recherche })}`);
}

export function getTransactionsBancaires(filtres: FiltresTransactionsBancaires = {}): Promise<PageResultats<TransactionBancaire>> {
  return apiFetch(`/banque/transactions?${parametres(filtres)}`);
}

export function getTransactionBancaire(id: number): Promise<TransactionBancaire> {
  return apiFetch(`/banque/transactions/${id}`);
}

export function creerTransactionBancaire(saisie: SaisieTransactionBancaire): Promise<TransactionBancaire> {
  return apiFetch('/banque/transactions', { method: 'POST', body: JSON.stringify(saisie), retries: 0 });
}

export function annulerTransactionBancaire(id: number, motif?: string | null): Promise<TransactionBancaire> {
  return apiFetch(`/banque/transactions/${id}/annuler`, { method: 'POST', body: JSON.stringify({ motif: motif || null }), retries: 0 });
}

// === Rapprochement bancaire ===

export interface RapprochementBancaire {
  id: number;
  compte_bancaire_id: number;
  date_releve: string;
  solde_releve: string;
  solde_depart: string;
  statut: 'en_cours' | 'termine';
  notes: string | null;
  auteur?: { id: number; nom: string } | null;
  auteur_fin?: { id: number; nom: string } | null;
  termine_le: string | null;
}

export interface LigneRapprochement {
  id: number;
  ecriture_id: number;
  date: string;
  numero: string;
  journal: string;
  libelle: string;
  reference: string | null;
  source: SourceEcriture;
  entree: number;
  sortie: number;
  pointee: boolean;
}

export interface ResumeRapprochement {
  solde_depart: number;
  entrees_pointees: number;
  sorties_pointees: number;
  solde_pointe: number;
  solde_releve: number;
  ecart: number;
  entrees_en_circulation: number;
  sorties_en_circulation: number;
  solde_comptable: number;
}

export interface DetailRapprochement {
  rapprochement: RapprochementBancaire;
  lignes: LigneRapprochement[];
  resume: ResumeRapprochement;
}

export function getRapprochements(compteId: number): Promise<RapprochementBancaire[]> {
  return apiFetch(`/banque/comptes/${compteId}/rapprochements`);
}

export function commencerRapprochement(compteId: number, donnees: { date_releve: string; solde_releve: number; notes?: string | null }): Promise<RapprochementBancaire> {
  return apiFetch(`/banque/comptes/${compteId}/rapprochements`, { method: 'POST', body: JSON.stringify(donnees), retries: 0 });
}

export function getRapprochement(id: number): Promise<DetailRapprochement> {
  return apiFetch(`/banque/rapprochements/${id}`);
}

export function modifierRapprochement(id: number, donnees: { date_releve?: string; solde_releve?: number; notes?: string | null }): Promise<DetailRapprochement> {
  return apiFetch(`/banque/rapprochements/${id}`, { method: 'PUT', body: JSON.stringify(donnees), retries: 0 });
}

export function pointerMouvements(id: number, lignes: number[], pointee: boolean): Promise<DetailRapprochement> {
  return apiFetch(`/banque/rapprochements/${id}/pointer`, { method: 'POST', body: JSON.stringify({ lignes, pointee }), retries: 0 });
}

export function terminerRapprochement(id: number): Promise<RapprochementBancaire> {
  return apiFetch(`/banque/rapprochements/${id}/terminer`, { method: 'POST', retries: 0 });
}

export function rouvrirRapprochement(id: number): Promise<RapprochementBancaire> {
  return apiFetch(`/banque/rapprochements/${id}/rouvrir`, { method: 'POST', retries: 0 });
}

export function supprimerRapprochement(id: number): Promise<{ message: string }> {
  return apiFetch(`/banque/rapprochements/${id}`, { method: 'DELETE', retries: 0 });
}
