import { apiFetch } from '@/services/http';

/**
 * Virements aux fournisseurs (Achats → Virements) : les déboursés payés par virement, transmis à la banque en lots,
 * dans un fichier SEPA (pain.001) ou CSV ; un lot refusé par la banque remet ses déboursés à transmettre.
 */

export type StatutVirement = 'a_transmettre' | 'emis' | 'refuse';
export const STATUTS_VIREMENT: StatutVirement[] = ['a_transmettre', 'emis', 'refuse'];
export type FormatVirement = 'sepa' | 'csv';

export interface CompteVirement {
  id: number;
  nom: string;
  banque: string;
  iban: string | null;
  bic: string | null;
  devise_code: string | null;
  virement_format: FormatVirement | null;
  virement_emetteur: string | null;
  prochain_lot: number;
  iban_valide: boolean;
}

export interface DebourseVirement {
  id: number;
  numero: string;
  date_debourse: string;
  fournisseur: { id: number; code: string; raison_sociale: string } | null;
  montant: number;
  /** Vide : devise de l'entreprise. */
  devise: string | null;
  compte_bancaire: { id: number; nom: string; banque: string } | null;
  factures: string;
  /** Compte du fournisseur à créditer (ou crédité). */
  beneficiaire: {
    id: number; banque: string; titulaire: string | null; iban: string | null; bic: string | null; numero_compte: string | null;
    iban_valide: boolean; modifie_le: string | null;
  } | null;
  virement_statut: 'emis' | 'refuse' | null;
  lot: { id: number; numero: number; statut: string } | null;
  /** Ce qui manque ou inquiète (coordonnées récemment modifiées…). */
  alertes: string[];
}

export interface LotVirements {
  id: number;
  compte_bancaire_id: number;
  compte_bancaire?: { id: number; nom: string; banque: string } | null;
  numero: number;
  format: FormatVirement;
  date_execution: string;
  devise: string | null;
  nombre: number;
  total: string;
  fichier: string;
  statut: 'emis' | 'refuse';
  motif_refus: string | null;
  refuse_le: string | null;
  auteur?: { id: number; nom: string } | null;
  auteur_refus?: { id: number; nom: string } | null;
  created_at: string;
}

const parametres = (filtres: Record<string, string | number | undefined>) => {
  const p = new URLSearchParams();
  Object.entries(filtres).forEach(([cle, valeur]) => { if (valeur !== undefined && valeur !== '') p.set(cle, String(valeur)); });
  return p.toString();
};

export function getVirements(statut: StatutVirement, compteBancaireId?: number): Promise<{ debourses: DebourseVirement[]; comptes: CompteVirement[] }> {
  return apiFetch(`/debourses/virements?${parametres({ statut, compte_bancaire_id: compteBancaireId })}`);
}

export function getLotsVirements(compteBancaireId?: number): Promise<LotVirements[]> {
  return apiFetch(`/debourses/virements/lots?${parametres({ compte_bancaire_id: compteBancaireId })}`);
}

export function creerLotVirements(donnees: {
  compte_bancaire_id: number; debourses: number[]; format: FormatVirement; date_execution: string; numero?: number | null; emetteur?: string | null;
}): Promise<LotVirements> {
  return apiFetch('/debourses/virements/lots', { method: 'POST', body: JSON.stringify(donnees), retries: 0 });
}

/** Le fichier du lot, tel qu'il a été transmis, téléchargé avec l'en-tête d'authentification. */
export async function telechargerLotVirements(lot: LotVirements): Promise<void> {
  const fichier = await apiFetch<Blob>(`/debourses/virements/lots/${lot.id}/fichier`, { responseType: 'blob' });
  const lien = document.createElement('a');
  lien.href = URL.createObjectURL(fichier);
  lien.download = lot.fichier;
  lien.click();
  setTimeout(() => URL.revokeObjectURL(lien.href), 1000);
}

export function refuserLotVirements(id: number, motif?: string | null): Promise<LotVirements> {
  return apiFetch(`/debourses/virements/lots/${id}/refuser`, { method: 'POST', body: JSON.stringify({ motif: motif || null }), retries: 0 });
}

/** IBAN lisible par groupes de quatre. */
export const ibanLisible = (iban: string | null | undefined) => (iban ?? '').replace(/\s+/g, '').replace(/(.{4})/g, '$1 ').trim();
