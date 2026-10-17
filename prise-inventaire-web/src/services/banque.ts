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
  /** Prochain numéro du chéquier (chèques des déboursés). */
  prochain_cheque?: number | null;
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

/** Réglages d'un compte bancaire : seuls ceux envoyés changent. */
export function relierCompteBancaire(id: number, donnees: { compte_comptable_id?: number | null; journal_id?: number | null; prochain_cheque?: number | null }): Promise<CompteBanque> {
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

export function pointerReleveRapprochement(id: number): Promise<DetailRapprochement & { pointes: number }> {
  return apiFetch(`/banque/rapprochements/${id}/pointer-releve`, { method: 'POST', retries: 0 });
}

// === Relevés bancaires importés ===

export type StatutOperationReleve = 'non_reliee' | 'partielle' | 'reliee' | 'exclue';
export const STATUTS_OPERATION_RELEVE: StatutOperationReleve[] = ['non_reliee', 'partielle', 'reliee', 'exclue'];

/** Mouvement du compte comptable relié à une opération du relevé. */
export interface MouvementRelie {
  id: number;
  date: string;
  numero: string;
  journal: string;
  libelle: string;
  reference: string | null;
  source: SourceEcriture;
  /** Signé : positif quand l'argent entre. */
  montant: number;
}

/** Mouvement libre qui peut correspondre à une opération. */
export interface CandidatReleve extends Omit<MouvementRelie, 'montant'> {
  /** Montant sans signe ; `signe` : positif quand l'argent entre. */
  montant: number;
  signe: number;
}

export interface OperationReleve {
  id: number;
  date_operation: string;
  libelle: string;
  reference: string | null;
  /** Signé : positif quand l'argent entre sur le compte. */
  montant: number;
  statut: StatutOperationReleve;
  motif_exclusion: string | null;
  montant_relie: number;
  mouvements: MouvementRelie[];
  /** Meilleure correspondance proposée, jamais appliquée seule. */
  suggestion?: CandidatReleve | null;
}

/** Colonnes du CSV de la banque, numérotées à partir de 1 ; mémorisées sur le compte au dernier import. */
export interface ReglagesImportReleve {
  colonne_date?: number;
  colonne_libelle?: number;
  colonne_montant?: number;
  colonne_debit?: number;
  colonne_credit?: number;
  colonne_reference?: number;
  ligne_debut?: number;
  format_date?: 'jma' | 'amj' | 'mja';
}

export interface ReleveCompte {
  import_reglages: ReglagesImportReleve | null;
  du: string;
  au: string;
  lignes: OperationReleve[];
  resume: Record<StatutOperationReleve, number> & { suggestions: number };
}

export type FormatReleve = 'csv' | 'ofx' | 'camt';

export interface RapportImportReleve {
  format: FormatReleve;
  lues: number;
  nouvelles: number;
  doublons: number;
  ignorees: { ligne: number; raison: string }[];
  /** Somme signée des opérations nouvelles. */
  total: number;
  apercu: { date: string; libelle: string; reference: string | null; montant: number }[];
  /** Null : aperçu seul, ou rien de nouveau. */
  releve_id: number | null;
}

/** Fichier importé : un import sans opération reliée se supprime (colonnes mal réglées, mauvais compte). */
export interface ImportReleve {
  id: number;
  fichier: string;
  format: FormatReleve;
  importe_le: string | null;
  auteur: string | null;
  /** Période couverte par ses opérations. */
  du: string | null;
  au: string | null;
  nombre_lignes: number;
  doublons: number;
  reliees: number;
}

export function getImportsReleve(compteId: number): Promise<ImportReleve[]> {
  return apiFetch(`/banque/comptes/${compteId}/releves`);
}

export function supprimerImportReleve(id: number): Promise<{ message: string }> {
  return apiFetch(`/banque/releves/${id}`, { method: 'DELETE', retries: 0 });
}

export function getReleve(compteId: number, filtres: { du?: string; au?: string; statut?: StatutOperationReleve | '' } = {}): Promise<ReleveCompte> {
  return apiFetch(`/banque/comptes/${compteId}/releve?${parametres(filtres)}`);
}

/** Importe un fichier CSV, OFX ou CAMT ; `simulation` : l'aperçu seul. */
export function importerReleve(compteId: number, fichier: File, reglages: ReglagesImportReleve, simulation: boolean): Promise<RapportImportReleve> {
  const formulaire = new FormData();
  formulaire.append('fichier', fichier);
  formulaire.append('simulation', simulation ? '1' : '0');
  Object.entries(reglages).forEach(([cle, valeur]) => {
    if (valeur !== undefined && valeur !== null && valeur !== '') formulaire.append(cle, String(valeur));
  });
  return apiFetch(`/banque/comptes/${compteId}/releves`, { method: 'POST', body: formulaire, retries: 0, timeoutMs: 120000 });
}

export function getCandidatsReleve(operationId: number, recherche?: string): Promise<CandidatReleve[]> {
  return apiFetch(`/banque/releve-lignes/${operationId}/candidats?${parametres({ recherche })}`);
}

export function relierOperation(operationId: number, lignes: number[]): Promise<OperationReleve> {
  return apiFetch(`/banque/releve-lignes/${operationId}/relier`, { method: 'POST', body: JSON.stringify({ lignes }), retries: 0 });
}

/** Retire un mouvement de l'opération, ou tous sans `ligne`. */
export function delierOperation(operationId: number, ligne?: number): Promise<OperationReleve> {
  return apiFetch(`/banque/releve-lignes/${operationId}/delier`, { method: 'POST', body: JSON.stringify({ ligne: ligne ?? null }), retries: 0 });
}

export function exclureOperation(operationId: number, motif?: string | null): Promise<OperationReleve> {
  return apiFetch(`/banque/releve-lignes/${operationId}/exclure`, { method: 'POST', body: JSON.stringify({ motif: motif || null }), retries: 0 });
}

export function retablirOperation(operationId: number): Promise<OperationReleve> {
  return apiFetch(`/banque/releve-lignes/${operationId}/retablir`, { method: 'POST', retries: 0 });
}

/** Crée la transaction bancaire d'une opération sans mouvement, la passe au journal et la relie. */
export function classerOperation(operationId: number, donnees: { compte_comptable_id: number; libelle?: string | null }): Promise<{ ligne: OperationReleve; transaction: TransactionBancaire; message: string }> {
  return apiFetch(`/banque/releve-lignes/${operationId}/classer`, { method: 'POST', body: JSON.stringify(donnees), retries: 0 });
}

/** Applique toutes les meilleures correspondances proposées sur la période. */
export function relierSuggestionsReleve(compteId: number, du?: string, au?: string): Promise<{ message: string; reliees: number }> {
  return apiFetch(`/banque/comptes/${compteId}/releve/relier-suggestions?${parametres({ du, au })}`, { method: 'POST', retries: 0 });
}
