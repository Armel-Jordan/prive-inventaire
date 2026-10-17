import { apiFetch } from '@/services/http';
import type { PageResultats } from '@/services/api';
import type { ProduitDocument } from '@/services/avoirs';

/**
 * Retours fournisseurs et avoirs : marchandise renvoyée à un fournisseur (reçue d'une de ses commandes, rapportée par
 * un client, ou sans origine), expédiée puis close quand il a répondu : crédit obtenu, remplacement reçu, contenant
 * rendu (module Achats).
 */

export type StatutRetourFournisseur = 'brouillon' | 'expedie' | 'clos';
export const STATUTS_RETOUR_FOURNISSEUR: StatutRetourFournisseur[] = ['brouillon', 'expedie', 'clos'];
export type TypeRetourFournisseur = 'remboursement' | 'remplacement' | 'consigne';
export const TYPES_RETOUR_FOURNISSEUR: TypeRetourFournisseur[] = ['remboursement', 'remplacement', 'consigne'];

export interface RetourFournisseurLigne {
  id: number;
  com_four_ligne_id: number | null;
  retour_client_ligne_id: number | null;
  produit_id: number;
  produit?: ProduitDocument | null;
  quantite: number;
  prix_unitaire: string;
  raison_id: number | null;
  raison?: { id: number; code: string; libelle: string } | null;
  /** Secteur d'où la marchandise sort à l'expédition (aucun : elle n'était pas en stock). */
  secteur_id: number | null;
  secteur?: { id: number; code: string; nom: string } | null;
  mouvement_id: number | null;
  mouvement_remplacement_id: number | null;
  notes: string | null;
  /** Produit suivi : le lot qui repart, les numéros de série renvoyés. */
  lot_id?: number | null;
  numeros_serie?: string[] | null;
}

export interface RetourFournisseur {
  id: number;
  numero: string | null;
  fournisseur_id: number;
  commande_fournisseur_id: number | null;
  retour_client_id: number | null;
  type: TypeRetourFournisseur;
  statut: StatutRetourFournisseur;
  date_retour: string;
  date_expedition: string | null;
  date_cloture: string | null;
  numero_rma: string | null;
  /** Devise des montants (null : celle de l'entreprise). */
  devise: string | null;
  taux_change: string | number;
  montant: string;
  montant_credite: string | null;
  reference_avoir: string | null;
  secteur_remplacement_id: number | null;
  notes: string | null;
  fournisseur?: { id: number; code: string; raison_sociale: string; email?: string | null; devise?: string | null };
  commande?: { id: number; numero: string } | null;
  retour_client?: { id: number; numero: string | null; client?: { id: number; raison_sociale: string } | null } | null;
  secteur_remplacement?: { id: number; code: string; nom: string } | null;
  lignes?: RetourFournisseurLigne[];
  lignes_count?: number;
  quantite_totale?: number | string | null;
  auteur?: { id: number; nom: string } | null;
  expediteur?: { id: number; nom: string } | null;
  operateur?: { id: number; nom: string } | null;
}

/** Résumé de la sélection, dans la devise de l'entreprise. */
export interface ResumeRetoursFournisseurs {
  credits_attendus: number;
  credits_obtenus: number;
  en_cours: number;
  brouillons: number;
}

export interface PropositionCommande {
  commande: { id: number; numero: string; statut: string; fournisseur_id: number; date_commande: string; fournisseur: { id: number; code: string; raison_sociale: string } | null };
  retournable: boolean;
  devise: { devise: string | null; taux_change: number };
  lignes: {
    com_four_ligne_id: number;
    produit_id: number;
    produit: ProduitDocument | null;
    quantite_recue: number;
    quantite_retournee: number;
    reste: number;
    prix_unitaire: number;
    /** Unités de stock par unité d'achat. */
    facteur?: number;
    secteur_id: number | null;
  }[];
}

export interface PropositionRetourClient {
  retour_client: { id: number; numero: string | null; statut: string; client_id: number; date_retour: string; client: { id: number; code: string; raison_sociale: string } | null };
  retournable: boolean;
  fournisseur_propose_id: number | null;
  lignes: {
    retour_client_ligne_id: number;
    produit_id: number;
    produit: ProduitDocument | null;
    traitement: 'remise_en_stock' | 'rebut';
    quantite_retournee_client: number;
    quantite_repartie: number;
    reste: number;
    quantite_proposee: number;
    secteur_id: number | null;
    raison_id: number | null;
  }[];
}

export interface SaisieLigneRetourFournisseur {
  com_four_ligne_id?: number;
  retour_client_ligne_id?: number;
  produit_id?: number;
  quantite: number;
  prix_unitaire: number;
  raison_id?: number | null;
  secteur_id?: number | null;
  /** Produit suivi : le lot qui repart (vide : les premiers à périmer), les numéros de série renvoyés. */
  lot_id?: number | null;
  numeros_serie?: string[];
}

export interface SaisieRetourFournisseur {
  fournisseur_id?: number;
  type: TypeRetourFournisseur;
  date_retour: string;
  numero_rma?: string | null;
  devise?: string | null;
  taux_change?: number | null;
  notes?: string | null;
  lignes: SaisieLigneRetourFournisseur[];
}

export interface Cloture {
  date_cloture: string;
  montant_credite?: number | null;
  reference_avoir?: string | null;
  secteur_remplacement_id?: number | null;
}

export async function getRetoursFournisseurs(params: { statut?: string; type?: string; fournisseur_id?: number; recherche?: string; page?: number } = {}): Promise<PageResultats<RetourFournisseur> & { resume: ResumeRetoursFournisseurs }> {
  const query = new URLSearchParams();
  Object.entries(params).forEach(([cle, valeur]) => { if (valeur !== undefined && valeur !== '') query.set(cle, String(valeur)); });
  return apiFetch(`/retours-fournisseurs?${query.toString()}`);
}

export async function getRetourFournisseur(id: number): Promise<RetourFournisseur> {
  return apiFetch(`/retours-fournisseurs/${id}`);
}

/** Ce qui peut repartir d'une commande fournisseur. */
export async function getPropositionCommande(commandeId: number): Promise<PropositionCommande> {
  return apiFetch(`/retours-fournisseurs/depuis-commande/${commandeId}`);
}

/** Ce qu'un retour client traité peut renvoyer au fournisseur. */
export async function getPropositionRetourClient(retourId: number): Promise<PropositionRetourClient> {
  return apiFetch(`/retours-fournisseurs/depuis-retour-client/${retourId}`);
}

export async function creerRetourFournisseur(origine: { commande_fournisseur_id?: number; retour_client_id?: number }, saisie: SaisieRetourFournisseur): Promise<RetourFournisseur> {
  return apiFetch('/retours-fournisseurs', { method: 'POST', body: JSON.stringify({ ...origine, ...saisie }), retries: 0 });
}

export async function modifierRetourFournisseur(id: number, saisie: SaisieRetourFournisseur): Promise<RetourFournisseur> {
  return apiFetch(`/retours-fournisseurs/${id}`, { method: 'PUT', body: JSON.stringify(saisie), retries: 0 });
}

export async function expedierRetourFournisseur(id: number, dateExpedition?: string): Promise<RetourFournisseur> {
  return apiFetch(`/retours-fournisseurs/${id}/expedier`, { method: 'POST', body: JSON.stringify({ date_expedition: dateExpedition }), retries: 0 });
}

export async function cloturerRetourFournisseur(id: number, cloture: Cloture): Promise<RetourFournisseur> {
  return apiFetch(`/retours-fournisseurs/${id}/cloturer`, { method: 'POST', body: JSON.stringify(cloture), retries: 0 });
}

export async function supprimerRetourFournisseur(id: number): Promise<void> {
  return apiFetch(`/retours-fournisseurs/${id}`, { method: 'DELETE', retries: 0 });
}
