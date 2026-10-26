import { apiFetch } from '@/services/http';

/**
 * Frais additionnels et de livraison : réglage (Paramètres → Frais additionnels) et lignes de frais proposées pendant
 * la saisie d'un devis, d'une commande ou d'une facture.
 */

export type ModeFrais = 'montant' | 'pourcentage';
export type ApplicationFrais = 'document' | 'produits' | 'livraison';
export type RepetitionFrais = 'document' | 'ligne';
export type DocumentFrais = 'devis' | 'commande' | 'facture';
export const APPLICATIONS_FRAIS: ApplicationFrais[] = ['document', 'produits', 'livraison'];

export interface ProduitFrais { id: number; numero: string; description: string }

export interface Frais {
  id: number;
  libelle: string;
  produit_id: number;
  produit?: ProduitFrais | null;
  mode: ModeFrais;
  /** Montant fixe (devise de l'entreprise) ou pourcentage. */
  valeur: string;
  application: ApplicationFrais;
  repetition: RepetitionFrais;
  sur_devis: boolean;
  sur_commandes: boolean;
  sur_factures: boolean;
  actif: boolean;
  produits?: ProduitFrais[];
  /** Frais « par produit » appliqué aussi à toute matière dangereuse. */
  matieres_dangereuses?: boolean;
}

export interface SaisieFrais {
  libelle: string;
  produit_id: number;
  mode: ModeFrais;
  valeur: number;
  application: ApplicationFrais;
  repetition: RepetitionFrais;
  sur_devis: boolean;
  sur_commandes: boolean;
  sur_factures: boolean;
  actif: boolean;
  produits: number[];
  matieres_dangereuses: boolean;
}

/** Ligne de frais proposée pour le document en cours (dans sa devise). */
export interface FraisPropose {
  frais_id: number;
  libelle: string;
  produit_id: number;
  produit: ProduitFrais | null;
  quantite: number;
  prix_unitaire_ht: number;
  montant_ht: number;
}

export async function getFrais(params: { application?: ApplicationFrais; actifs?: boolean } = {}): Promise<Frais[]> {
  const query = new URLSearchParams();
  if (params.application) query.set('application', params.application);
  if (params.actifs) query.set('actifs', '1');
  return apiFetch(`/frais?${query.toString()}`);
}

export async function creerFrais(saisie: SaisieFrais): Promise<Frais> {
  return apiFetch('/frais', { method: 'POST', body: JSON.stringify(saisie), retries: 0 });
}

export async function modifierFrais(id: number, saisie: SaisieFrais): Promise<Frais> {
  return apiFetch(`/frais/${id}`, { method: 'PUT', body: JSON.stringify(saisie), retries: 0 });
}

export async function supprimerFrais(id: number): Promise<void> {
  return apiFetch(`/frais/${id}`, { method: 'DELETE', retries: 0 });
}

/** Lignes de frais à proposer pour un document en cours de saisie. */
export async function calculerFrais(donnees: {
  document: DocumentFrais;
  client_id: number | null;
  /** Transporteur du document : s'il ne facture pas la livraison, le frais de livraison n'est pas proposé. */
  transporteur_id?: number | null;
  taux_change?: number;
  lignes: { produit_id: number; quantite: number; prix_unitaire_ht: number; remise_ligne?: number }[];
}): Promise<FraisPropose[]> {
  return apiFetch('/frais/calculer', { method: 'POST', body: JSON.stringify(donnees), retries: 0 });
}
