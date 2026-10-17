import { apiFetch } from '@/services/http';
import type { ComFourLigne, Fournisseur, PageResultats, ReceptionLigne } from '@/services/api';

/**
 * Réceptions fournisseur documentées (module Achats) : une livraison donne un document numéroté (une ou plusieurs
 * commandes du même fournisseur, bon de livraison, connaissement, coût réel), qui se corrige ou s'annule.
 */

export type StatutReception = 'enregistree' | 'annulee';

/** Ligne d'une réception, avec sa ligne de commande (commande, produit, prix commandé) et son secteur. */
export interface LigneReceptionDocument extends ReceptionLigne {
  ligne_commande?: ComFourLigne & { commande?: { id: number; numero: string; devise?: string | null; taux_change?: number | string } };
  /** Coût retenu : le coût saisi, sinon le prix commandé. */
  cout_retenu?: number;
  montant?: number;
}

export interface ReceptionDocument {
  id: number;
  numero: string;
  fournisseur_id: number;
  fournisseur?: Pick<Fournisseur, 'id' | 'code' | 'raison_sociale'>;
  date_reception: string;
  bon_livraison: string | null;
  connaissement: string | null;
  notes: string | null;
  statut: StatutReception;
  auteur?: { id: number; nom: string } | null;
  annulee_le: string | null;
  auteur_annulation?: { id: number; nom: string } | null;
  motif_annulation: string | null;
  created_at: string;
  /** Numéros des commandes reçues. */
  commandes: string[];
  /** Devise des commandes quand elles en ont une seule (sinon null) ; montants dans cette devise et dans celle de l'entreprise. */
  devise: string | null;
  montant: number;
  montant_reference: number;
  lignes_count?: number;
  lignes?: LigneReceptionDocument[];
}

/** Ligne saisie : une ligne de commande peut revenir plusieurs fois, une par secteur où la marchandise est rangée. */
export interface SaisieReception {
  com_four_ligne_id: number;
  quantite_recue: number;
  cout_unitaire?: number | null;
  secteur_id?: number;
  lot_numero?: string;
  date_peremption?: string;
  notes?: string;
}

export interface NouvelleReception {
  date_reception: string;
  bon_livraison?: string;
  connaissement?: string;
  notes?: string;
  /** Recevoir plus que le reste commandé, après confirmation. */
  accepter_surplus?: boolean;
  receptions: SaisieReception[];
  /** Lignes de commande que le fournisseur ne livrera pas au complet. */
  clotures?: number[];
}

export interface ResultatReception {
  message: string;
  reception: ReceptionDocument | null;
  receptions: ReceptionLigne[];
  cloturees: number[];
}

export interface FiltresReceptions {
  recherche?: string;
  fournisseur_id?: number;
  statut?: StatutReception | '';
  date_debut?: string;
  date_fin?: string;
  page?: number;
  per_page?: number;
}

export function enregistrerReception(reception: NouvelleReception): Promise<ResultatReception> {
  return apiFetch('/receptions/multiple', { method: 'POST', body: JSON.stringify(reception), retries: 0 });
}

export function getReceptionsDocuments(filtres: FiltresReceptions = {}): Promise<PageResultats<ReceptionDocument>> {
  const parametres = new URLSearchParams();
  Object.entries(filtres).forEach(([cle, valeur]) => {
    if (valeur !== undefined && valeur !== null && valeur !== '') parametres.set(cle, String(valeur));
  });
  return apiFetch(`/receptions/documents?${parametres}`);
}

export function getReceptionDocument(id: number): Promise<ReceptionDocument> {
  return apiFetch(`/receptions/documents/${id}`);
}

/** Correction : date, références, note et coût des lignes (les quantités ne se corrigent pas). */
export function modifierReception(id: number, donnees: {
  date_reception?: string;
  bon_livraison?: string | null;
  connaissement?: string | null;
  notes?: string | null;
  lignes?: { id: number; cout_unitaire: number | null }[];
}): Promise<ReceptionDocument> {
  return apiFetch(`/receptions/documents/${id}`, { method: 'PUT', body: JSON.stringify(donnees), retries: 0 });
}

export function annulerReception(id: number, motif: string): Promise<ReceptionDocument> {
  return apiFetch(`/receptions/documents/${id}/annuler`, { method: 'POST', body: JSON.stringify({ motif: motif || null }), retries: 0 });
}
