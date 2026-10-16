import { apiFetch } from '@/services/http';

/** Comptabilité générale : plan comptable, plans types, exercices et périodes (module Finance). */

export type NatureCompte = 'actif' | 'passif' | 'capitaux_propres' | 'produit' | 'charge';
export type TypeCompte = 'standard' | 'en_tete' | 'clients' | 'fournisseurs' | 'tresorerie' | 'resultat';
export type Decoupage = 'mensuel' | 'trimestriel' | 'unique';

export const NATURES: NatureCompte[] = ['actif', 'passif', 'capitaux_propres', 'produit', 'charge'];
export const TYPES_COMPTE: TypeCompte[] = ['standard', 'en_tete', 'clients', 'fournisseurs', 'tresorerie', 'resultat'];
export const DECOUPAGES: Decoupage[] = ['mensuel', 'trimestriel', 'unique'];

export interface CompteComptable {
  id: number;
  numero: string;
  libelle: string;
  parent_id: number | null;
  nature: NatureCompte;
  type: TypeCompte;
  saisie_manuelle: boolean;
  actif: boolean;
  notes: string | null;
}

export type SaisieCompte = Omit<CompteComptable, 'id'>;

export interface PlanType {
  code: string;
  libelle: string;
  description: string;
  nombre_comptes: number;
}

export interface PeriodeComptable {
  id: number;
  exercice_id: number;
  numero: number;
  date_debut: string;
  date_fin: string;
  fermee: boolean;
  fermee_le: string | null;
  fermee_par: string | null;
  courante: boolean;
}

export interface ExerciceComptable {
  id: number;
  libelle: string;
  date_debut: string;
  date_fin: string;
  statut: 'ouvert' | 'cloture';
  cloture_le: string | null;
  cloture_par: string | null;
  decoupage: Decoupage;
  periodes: PeriodeComptable[];
}

export interface SaisieExercice {
  libelle: string;
  date_debut: string;
  date_fin: string;
  decoupage: Decoupage;
}

export async function getComptes(): Promise<CompteComptable[]> {
  return apiFetch('/comptabilite/comptes');
}

export async function creerCompte(compte: SaisieCompte): Promise<CompteComptable> {
  return apiFetch('/comptabilite/comptes', { method: 'POST', body: JSON.stringify(compte) });
}

export async function modifierCompte(id: number, compte: SaisieCompte): Promise<CompteComptable> {
  return apiFetch(`/comptabilite/comptes/${id}`, { method: 'PUT', body: JSON.stringify(compte) });
}

export async function supprimerCompte(id: number): Promise<void> {
  await apiFetch(`/comptabilite/comptes/${id}`, { method: 'DELETE' });
}

export async function getPlansTypes(): Promise<PlanType[]> {
  return apiFetch('/comptabilite/plans-types');
}

export async function importerPlanType(plan: string, completer: boolean): Promise<{ crees: number; message: string }> {
  return apiFetch('/comptabilite/comptes/importer-plan', { method: 'POST', body: JSON.stringify({ plan, completer }) });
}

/** Import CSV : en cas d'erreurs, l'API répond 422 avec la liste `erreurs` (lignes en défaut). */
export async function importerFichierComptes(fichier: File): Promise<{ crees: number; ignores: number; message: string }> {
  const formulaire = new FormData();
  formulaire.append('fichier', fichier);
  return apiFetch('/comptabilite/comptes/importer-fichier', { method: 'POST', body: formulaire, timeoutMs: 120000 });
}

/** Plan comptable en CSV, téléchargé avec l'en-tête d'authentification. */
export async function exporterComptes(): Promise<void> {
  const fichier = await apiFetch<Blob>('/comptabilite/comptes/export', { responseType: 'blob' });
  const lien = document.createElement('a');
  lien.href = URL.createObjectURL(fichier);
  lien.download = 'plan-comptable.csv';
  lien.click();
  setTimeout(() => URL.revokeObjectURL(lien.href), 1000);
}

export async function getExercices(): Promise<{ exercices: ExerciceComptable[]; periode_courante_id: number | null }> {
  return apiFetch('/comptabilite/exercices');
}

export async function creerExercice(exercice: SaisieExercice): Promise<ExerciceComptable> {
  return apiFetch('/comptabilite/exercices', { method: 'POST', body: JSON.stringify(exercice) });
}

export async function modifierExercice(id: number, exercice: SaisieExercice): Promise<ExerciceComptable> {
  return apiFetch(`/comptabilite/exercices/${id}`, { method: 'PUT', body: JSON.stringify(exercice) });
}

export async function supprimerExercice(id: number): Promise<void> {
  await apiFetch(`/comptabilite/exercices/${id}`, { method: 'DELETE' });
}

export async function fermerPeriode(id: number): Promise<PeriodeComptable> {
  return apiFetch(`/comptabilite/periodes/${id}/fermer`, { method: 'POST' });
}

export async function rouvrirPeriode(id: number): Promise<PeriodeComptable> {
  return apiFetch(`/comptabilite/periodes/${id}/rouvrir`, { method: 'POST' });
}

// === Journal général et états ===

export type TypeJournal = 'general' | 'ventes' | 'achats' | 'tresorerie' | 'ouverture';
export type SourceEcriture = 'manuelle' | 'ouverture' | 'cloture' | 'recurrente';
export const TYPES_JOURNAL: TypeJournal[] = ['general', 'ventes', 'achats', 'tresorerie', 'ouverture'];

export interface JournalComptable {
  id: number;
  code: string;
  libelle: string;
  type: TypeJournal;
  actif: boolean;
  ecritures_count?: number;
}

export interface LigneEcriture {
  id?: number;
  compte_id: number;
  compte?: string;
  compte_libelle?: string;
  libelle: string | null;
  debit: number;
  credit: number;
}

export interface EcritureComptable {
  id: number;
  numero: string;
  date: string;
  libelle: string;
  reference: string | null;
  source: SourceEcriture;
  total: number;
  journal: { id: number; code: string; libelle: string } | null;
  createur: string | null;
  contrepassation_de: string | null;
  contrepassee_par: string | null;
  modifiable: boolean;
  lignes: LigneEcriture[];
}

export interface SaisieEcriture {
  journal_id: number;
  date: string;
  libelle: string;
  reference: string | null;
  lignes: { compte_id: number; libelle: string | null; debit: number; credit: number }[];
}

export interface PageEcritures {
  data: EcritureComptable[];
  current_page: number;
  last_page: number;
  total: number;
}

export interface FiltresEcritures {
  du?: string;
  au?: string;
  journal_id?: number;
  compte_id?: number;
  source?: SourceEcriture;
  recherche?: string;
  page?: number;
}

export interface LigneBalance {
  compte_id: number;
  numero: string;
  libelle: string;
  nature: NatureCompte;
  ouverture_debit: number;
  ouverture_credit: number;
  mouvement_debit: number;
  mouvement_credit: number;
  solde_debit: number;
  solde_credit: number;
}

export interface Balance {
  du: string;
  au: string;
  comptes: LigneBalance[];
  totaux: Omit<LigneBalance, 'compte_id' | 'numero' | 'libelle' | 'nature'>;
  equilibree: boolean;
}

export interface LigneGrandLivre {
  ecriture_id: number;
  date: string;
  numero: string;
  journal: string;
  compte: string;
  libelle: string;
  reference: string | null;
  debit: number;
  credit: number;
  solde: number;
}

export interface GrandLivre {
  compte: { id: number; numero: string; libelle: string; nature: NatureCompte; type: TypeCompte };
  du: string;
  au: string;
  solde_ouverture: number;
  lignes: LigneGrandLivre[];
  total_debit: number;
  total_credit: number;
  solde_final: number | null;
  tronque: boolean;
}

export interface SoldesOuverture {
  exercice: { id: number; libelle: string; date_debut: string; verrouille: boolean } | null;
  ecriture: { id: number; numero: string } | null;
  comptes: { id: number; numero: string; libelle: string; nature: NatureCompte; type: TypeCompte; debit: number; credit: number }[];
}

/** Montant comptable : deux décimales, sans symbole (les tableaux comptables portent la devise de l'entreprise). */
export function montant(valeur: number, locale: string): string {
  return valeur.toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function requete(filtres: object): string {
  const parametres = new URLSearchParams();
  Object.entries(filtres).forEach(([cle, valeur]) => { if (valeur !== undefined && valeur !== '' && valeur !== null) parametres.set(cle, String(valeur)); });
  return parametres.toString();
}

async function telecharger(chemin: string, nom: string): Promise<void> {
  const fichier = await apiFetch<Blob>(chemin, { responseType: 'blob', timeoutMs: 120000 });
  const lien = document.createElement('a');
  lien.href = URL.createObjectURL(fichier);
  lien.download = nom;
  lien.click();
  setTimeout(() => URL.revokeObjectURL(lien.href), 1000);
}

export async function getJournaux(): Promise<JournalComptable[]> {
  return apiFetch('/comptabilite/journaux');
}

export async function enregistrerJournal(journal: Omit<JournalComptable, 'id' | 'ecritures_count'>, id?: number): Promise<JournalComptable> {
  return apiFetch(id ? `/comptabilite/journaux/${id}` : '/comptabilite/journaux', { method: id ? 'PUT' : 'POST', body: JSON.stringify(journal) });
}

export async function supprimerJournal(id: number): Promise<void> {
  await apiFetch(`/comptabilite/journaux/${id}`, { method: 'DELETE' });
}

export async function getEcritures(filtres: FiltresEcritures): Promise<PageEcritures> {
  return apiFetch(`/comptabilite/ecritures?${requete(filtres)}`);
}

export async function enregistrerEcriture(ecriture: SaisieEcriture, id?: number): Promise<EcritureComptable> {
  return apiFetch(id ? `/comptabilite/ecritures/${id}` : '/comptabilite/ecritures', { method: id ? 'PUT' : 'POST', body: JSON.stringify(ecriture) });
}

export async function supprimerEcriture(id: number): Promise<void> {
  await apiFetch(`/comptabilite/ecritures/${id}`, { method: 'DELETE' });
}

export async function contrePasserEcriture(id: number, date: string): Promise<EcritureComptable> {
  return apiFetch(`/comptabilite/ecritures/${id}/contre-passer`, { method: 'POST', body: JSON.stringify({ date }) });
}

export async function exporterEcritures(du: string, au: string): Promise<void> {
  return telecharger(`/comptabilite/ecritures/export?${requete({ du, au })}`, `ecritures-${du}-${au}.csv`);
}

export async function getBalance(du?: string, au?: string): Promise<Balance> {
  return apiFetch(`/comptabilite/balance?${requete({ du, au })}`);
}

export async function exporterBalance(du: string, au: string): Promise<void> {
  return telecharger(`/comptabilite/balance?${requete({ du, au, format: 'csv' })}`, `balance-${du}-${au}.csv`);
}

export async function getGrandLivre(compteId: number, du?: string, au?: string, recherche?: string): Promise<GrandLivre> {
  return apiFetch(`/comptabilite/grand-livre?${requete({ compte_id: compteId, du, au, recherche })}`);
}

export async function exporterGrandLivre(compteId: number, numero: string, du: string, au: string): Promise<void> {
  return telecharger(`/comptabilite/grand-livre?${requete({ compte_id: compteId, du, au, format: 'csv' })}`, `grand-livre-${numero}-${du}-${au}.csv`);
}

export async function getSoldesOuverture(): Promise<SoldesOuverture> {
  return apiFetch('/comptabilite/soldes-ouverture');
}

export async function enregistrerSoldesOuverture(lignes: { compte_id: number; debit: number; credit: number }[]): Promise<{ message: string; ecriture: { id: number; numero: string } | null }> {
  return apiFetch('/comptabilite/soldes-ouverture', { method: 'PUT', body: JSON.stringify({ lignes }), timeoutMs: 120000 });
}
