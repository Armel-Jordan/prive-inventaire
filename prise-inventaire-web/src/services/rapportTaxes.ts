import { apiFetch } from '@/services/http';

/**
 * Rapport des taxes (Finance → Rapport des taxes) : taxes collectées sur les ventes (diminuées des notes de crédit) et
 * déductibles sur les achats, taxe par taxe, en détail, par jour ou pour la période ; sommaire par taxe et comptes de
 * taxes du journal.
 */

export type NiveauRapportTaxes = 'detail' | 'jour' | 'periode';
export const NIVEAUX_RAPPORT_TAXES: NiveauRapportTaxes[] = ['detail', 'jour', 'periode'];

export interface TaxeRapport {
  /** « taxe:3 » (taxe de l'entreprise), « tva:19.25 » (ligne au taux simple). */
  cle: string;
  nom: string;
}

export interface MontantsTaxes {
  ventes_ht: number;
  achats_ht: number;
  /** Par clé de taxe, dans la devise de l'entreprise (une note de crédit en négatif). */
  collectee: Record<string, number>;
  deductible: Record<string, number>;
}

export interface LigneRapportTaxes extends MontantsTaxes {
  /** Null pour le total de la période. */
  date: string | null;
  /** Détail seulement. */
  type?: 'vente' | 'avoir' | 'achat';
  id?: number;
  numero?: string;
  reference?: string | null;
  tiers?: string;
}

export interface CompteTaxeJournal {
  id: number;
  numero: string;
  libelle: string;
  roles: ('collectee' | 'deductible')[];
  debit: number;
  credit: number;
  /** Crédit − débit. */
  solde: number;
}

export interface RapportTaxes {
  du: string;
  au: string;
  niveau: NiveauRapportTaxes;
  taxes: TaxeRapport[];
  lignes: LigneRapportTaxes[];
  tronque: boolean;
  totaux: MontantsTaxes;
  sommaire: (TaxeRapport & { collectee: number; deductible: number; difference: number })[];
  /** Différence positive : à reverser ; négative : à récupérer. */
  total: { collectee: number; deductible: number; difference: number };
  /** Null tant que la comptabilisation automatique n'est pas réglée. */
  comptes: { lignes: CompteTaxeJournal[]; solde: number } | null;
}

const parametres = (du: string, au: string, niveau: NiveauRapportTaxes, format?: 'csv' | 'pdf') =>
  new URLSearchParams({ du, au, niveau, ...(format ? { format } : {}) }).toString();

export function getRapportTaxes(du: string, au: string, niveau: NiveauRapportTaxes): Promise<RapportTaxes> {
  return apiFetch(`/comptabilite/etats/taxes?${parametres(du, au, niveau)}`);
}

/** Adresse du rapport en PDF (bouton PDF). */
export const cheminPdfRapportTaxes = (du: string, au: string, niveau: NiveauRapportTaxes) => `/comptabilite/etats/taxes?${parametres(du, au, niveau, 'pdf')}`;

/** Le rapport en CSV, téléchargé avec l'en-tête d'authentification. */
export async function telechargerRapportTaxes(du: string, au: string, niveau: NiveauRapportTaxes): Promise<void> {
  const fichier = await apiFetch<Blob>(`/comptabilite/etats/taxes?${parametres(du, au, niveau, 'csv')}`, { responseType: 'blob' });
  const lien = document.createElement('a');
  lien.href = URL.createObjectURL(fichier);
  lien.download = `rapport-taxes-${du}-${au}.csv`;
  lien.click();
  setTimeout(() => URL.revokeObjectURL(lien.href), 1000);
}
