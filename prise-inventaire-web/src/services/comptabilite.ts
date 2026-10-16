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
