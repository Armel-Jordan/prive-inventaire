import { apiFetch } from '@/services/http';

/** Chargement du camion d'une tournée : colis et vrac cochés, étiquettes scannées. */

export interface ElementCharge { produit: { id: number; numero: string; description: string } | null; quantite: number }

export interface ColisACharger {
  id: number;
  numero: string;
  poids_kg: string | null;
  contenu: ElementCharge[];
  charge_le: string | null;
  charge_par: string | null;
}

export interface ArretACharger {
  ordre: number;
  bon_livraison_id: number;
  bon: string;
  client: string | null;
  colis: ColisACharger[];
  /** Marchandise du bon qui n'est dans aucun colis. */
  vrac: ElementCharge[];
  vrac_charge_le: string | null;
  vrac_charge_par: string | null;
}

export interface ResumeChargement { colis: number; colis_charges: number; vrac: number; vrac_charges: number; reste: number; commence: boolean }

export interface EtatChargement {
  tournee: { id: number; numero: string; statut: string; camion: string | null };
  /** Tournée pas encore partie : le chargement se coche. */
  modifiable: boolean;
  /** Arrêts dans l'ordre de chargement : le dernier livré d'abord. */
  arrets: ArretACharger[];
  resume: ResumeChargement;
}

export const getChargement = (tourneeId: number) => apiFetch<EtatChargement>(`/tournees/${tourneeId}/chargement`);

export const chargerColis = (tourneeId: number, colisId: number, charge: boolean) =>
  apiFetch<EtatChargement>(`/tournees/${tourneeId}/chargement/colis/${colisId}`, { method: 'POST', body: JSON.stringify({ charge }) });

export const chargerVrac = (tourneeId: number, bonId: number, charge: boolean) =>
  apiFetch<EtatChargement>(`/tournees/${tourneeId}/chargement/vrac/${bonId}`, { method: 'POST', body: JSON.stringify({ charge }) });

export const scannerColis = (tourneeId: number, numero: string) =>
  apiFetch<EtatChargement & { message: string; colis_id: number; deja: boolean }>(`/tournees/${tourneeId}/chargement/scan`, { method: 'POST', body: JSON.stringify({ numero }) });

/**
 * Le départ prévient quand le chargement n'est pas fini : des choses non cochées, alors que le chargement a commencé
 * ou que des colis ont été préparés (sans colis ni pointage, l'entreprise ne se sert pas du chargement).
 */
export const chargementIncomplet = (resume?: ResumeChargement | null): resume is ResumeChargement =>
  !!resume && resume.reste > 0 && (resume.commence || resume.colis > 0);
