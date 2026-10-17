import { apiFetch } from '@/services/http';
import type { PageResultats } from '@/services/api';
import type { Avoir, ProduitDocument } from '@/services/avoirs';

/**
 * Retours clients : marchandise rapportée par un client, avec ou sans facture d'origine, remise en stock dans un
 * secteur ou mise au rebut ; le retour d'une facture donne ensuite sa note de crédit (module Ventes).
 */

export type StatutRetour = 'brouillon' | 'traite';
export const STATUTS_RETOUR: StatutRetour[] = ['brouillon', 'traite'];
export type TraitementRetour = 'remise_en_stock' | 'rebut';

export interface RetourClientLigne {
  id: number;
  facture_ligne_id: number | null;
  produit_id: number;
  produit?: ProduitDocument | null;
  quantite: number;
  raison_id: number | null;
  raison?: { id: number; code: string; libelle: string } | null;
  traitement: TraitementRetour;
  secteur_id: number | null;
  secteur?: { id: number; code: string; nom: string } | null;
  /** Arrivage créé par la remise en stock. */
  mouvement_id: number | null;
  notes: string | null;
  /** Produit suivi : le lot revenu, les numéros de série rapportés. */
  lot_id?: number | null;
  numeros_serie?: string[] | null;
}

export interface RetourClient {
  id: number;
  numero: string | null;
  client_id: number;
  facture_id: number | null;
  date_retour: string;
  statut: StatutRetour;
  notes: string | null;
  avoir_id: number | null;
  traite_le: string | null;
  client?: { id: number; code: string; raison_sociale: string };
  facture?: { id: number; numero: string; date_facture?: string; statut?: string } | null;
  avoir?: { id: number; numero: string | null; statut: string; montant_ttc?: string; devise?: string | null } | null;
  lignes?: RetourClientLigne[];
  lignes_count?: number;
  quantite_totale?: number | string | null;
  auteur?: { id: number; nom: string } | null;
  operateur?: { id: number; nom: string } | null;
}

/** Quantités des retours traités de la sélection. */
export interface ResumeRetours {
  remis_en_stock: number;
  rebut: number;
  par_raison: { raison_id: number | null; libelle: string | null; quantite: number }[];
}

export interface LigneRetournable {
  facture_ligne_id: number;
  produit_id: number;
  produit: ProduitDocument | null;
  /** Secteur du produit, proposé pour la remise en stock. */
  secteur_id: number | null;
  quantite_facturee: number;
  /** Unité facturée (la caisse de 12) ; vide : l'unité de stock. */
  unite?: string | null;
  /** Unités de stock par unité facturée. */
  facteur?: number;
  quantite_retournee: number;
  reste: number;
}

export interface PropositionRetour {
  facture: { id: number; numero: string; statut: string; client_id: number; date_facture: string; client: { id: number; code: string; raison_sociale: string } | null };
  retournable: boolean;
  lignes: LigneRetournable[];
}

export interface SaisieLigneRetour {
  facture_ligne_id?: number;
  produit_id?: number;
  quantite: number;
  raison_id?: number | null;
  traitement: TraitementRetour;
  secteur_id?: number | null;
  notes?: string | null;
  /** Produit suivi : le lot qui revient (vide : le dernier livré au client), les numéros de série rapportés. */
  lot_id?: number | null;
  numeros_serie?: string[];
}

export interface SaisieRetour {
  date_retour: string;
  notes?: string | null;
  lignes: SaisieLigneRetour[];
}

export async function getRetoursClients(params: { statut?: string; client_id?: number; facture_id?: number; recherche?: string; page?: number } = {}): Promise<PageResultats<RetourClient> & { resume: ResumeRetours }> {
  const query = new URLSearchParams();
  Object.entries(params).forEach(([cle, valeur]) => { if (valeur !== undefined && valeur !== '') query.set(cle, String(valeur)); });
  return apiFetch(`/retours-clients?${query.toString()}`);
}

export async function getRetourClient(id: number): Promise<RetourClient> {
  return apiFetch(`/retours-clients/${id}`);
}

/** Ce qu'on peut encore retourner d'une facture. */
export async function getPropositionRetour(factureId: number): Promise<PropositionRetour> {
  return apiFetch(`/factures/${factureId}/retour`);
}

/** Nouveau retour : d'une facture (son client), ou sans facture pour le client indiqué. */
export async function creerRetourClient(origine: { facture_id: number } | { client_id: number }, saisie: SaisieRetour): Promise<RetourClient> {
  return apiFetch('/retours-clients', { method: 'POST', body: JSON.stringify({ ...origine, ...saisie }), retries: 0 });
}

export async function modifierRetourClient(id: number, saisie: SaisieRetour): Promise<RetourClient> {
  return apiFetch(`/retours-clients/${id}`, { method: 'PUT', body: JSON.stringify(saisie), retries: 0 });
}

export async function traiterRetourClient(id: number): Promise<RetourClient> {
  return apiFetch(`/retours-clients/${id}/traiter`, { method: 'POST', retries: 0 });
}

/** Note de crédit brouillon du retour, sur sa facture d'origine. */
export async function crediterRetourClient(id: number): Promise<Avoir> {
  return apiFetch(`/retours-clients/${id}/crediter`, { method: 'POST', retries: 0 });
}

export async function supprimerRetourClient(id: number): Promise<void> {
  return apiFetch(`/retours-clients/${id}`, { method: 'DELETE', retries: 0 });
}
