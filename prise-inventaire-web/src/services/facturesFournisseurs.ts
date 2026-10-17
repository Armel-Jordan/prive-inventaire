import { apiFetch } from '@/services/http';
import type { PageResultats } from '@/services/api';

/**
 * Factures fournisseurs (comptes à payer, module Achats) : saisie à la main ou depuis des réceptions, rapprochement avec
 * la valeur reçue, paiements jusqu'au reste dû, annulation.
 */

export type EtatFactureFournisseur = 'impayee' | 'partielle' | 'payee' | 'en_retard' | 'annulee';
export type ModePaiementFournisseur = 'especes' | 'cheque' | 'virement' | 'carte' | 'autre';

export interface LigneFactureFournisseur {
  id?: number;
  produit_id?: number | null;
  produit?: { id: number; numero: string; description: string } | null;
  description: string;
  quantite: number | string;
  prix_unitaire: number | string;
  montant_ht?: number | string;
  taux_tva?: number | string | null;
  groupe_taxe_id?: number | null;
  montant_tva?: number | string;
  montant_ttc?: number | string;
  /** Ligne de réception facturée (rapprochement). */
  reception_ligne_id?: number | null;
  /** Rapprochement : valeur reçue (quantité × coût réel, sinon commandé) et écart avec le montant facturé. */
  valeur_recue?: number;
  ecart?: number;
  cout_recu?: number;
}

export interface PaiementFournisseur {
  id: number;
  date_paiement: string;
  montant: number | string;
  mode: ModePaiementFournisseur;
  reference: string | null;
  notes: string | null;
  /** Escompte obtenu avec le paiement (déboursé). */
  escompte?: number | string;
  /** Déboursé dont le paiement est une part : il s'annule avec lui. */
  debourse?: { id: number; numero: string } | null;
  auteur?: { id: number; nom: string } | null;
}

export interface FactureFournisseur {
  id: number;
  numero: string;
  fournisseur_id: number;
  fournisseur?: { id: number; code: string; raison_sociale: string; devise?: string | null };
  numero_fournisseur: string | null;
  date_facture: string;
  date_echeance: string;
  condition_paiement_id: number | null;
  condition?: { id: number; code: string; libelle: string } | null;
  /** Vide : devise de l'entreprise. */
  devise: string | null;
  taux_change: number | string;
  montant_ht: number | string;
  montant_tva: number | string;
  montant_ttc: number | string;
  montant_paye: number | string;
  reste_a_payer: number;
  etat: EtatFactureFournisseur;
  jours_retard: number;
  statut: 'enregistree' | 'annulee';
  notes: string | null;
  auteur?: { id: number; nom: string } | null;
  annulee_le: string | null;
  auteur_annulation?: { id: number; nom: string } | null;
  motif_annulation: string | null;
  receptions_count?: number;
  receptions?: { id: number; numero: string; bon_livraison: string | null; date_reception: string }[];
  lignes?: LigneFactureFournisseur[];
  paiements?: PaiementFournisseur[];
  taxes?: { nom: string; taux: number; base: number; montant: number }[];
  /** Écart total facturé − reçu (lignes rapprochées), null sans réception. */
  ecart?: number | null;
}

export interface ResumeFacturesFournisseurs {
  a_payer: number;
  en_retard: number;
  nombre_a_payer: number;
  nombre_en_retard: number;
}

export interface FiltresFacturesFournisseurs {
  recherche?: string;
  fournisseur_id?: number;
  etat?: EtatFactureFournisseur | 'a_payer' | '';
  date_debut?: string;
  date_fin?: string;
  page?: number;
}

/** Facture à enregistrer (nouvelle ou modifiée). */
export interface SaisieFactureFournisseur {
  fournisseur_id: number;
  numero_fournisseur?: string | null;
  date_facture: string;
  date_echeance?: string | null;
  condition_paiement_id?: number | null;
  devise?: string | null;
  taux_change?: number | null;
  notes?: string | null;
  receptions?: number[];
  lignes: { produit_id?: number | null; description: string; quantite: number; prix_unitaire: number; taux_tva?: number | null; reception_ligne_id?: number | null }[];
}

/** Facture proposée depuis des réceptions. */
export interface PropositionFacture {
  fournisseur_id: number;
  devise: string | null;
  taux_change: number;
  receptions: { id: number; numero: string; bon_livraison: string | null }[];
  lignes: { produit_id: number | null; description: string; quantite: number; prix_unitaire: number; reception_ligne_id: number }[];
}

export function getFacturesFournisseurs(filtres: FiltresFacturesFournisseurs = {}): Promise<PageResultats<FactureFournisseur> & { resume: ResumeFacturesFournisseurs }> {
  const parametres = new URLSearchParams();
  Object.entries(filtres).forEach(([cle, valeur]) => {
    if (valeur !== undefined && valeur !== null && valeur !== '') parametres.set(cle, String(valeur));
  });
  return apiFetch(`/factures-fournisseurs?${parametres}`);
}

export function getFactureFournisseur(id: number): Promise<FactureFournisseur> {
  return apiFetch(`/factures-fournisseurs/${id}`);
}

export function propositionFacture(receptions: number[]): Promise<PropositionFacture> {
  const parametres = new URLSearchParams();
  receptions.forEach(id => parametres.append('receptions[]', String(id)));
  return apiFetch(`/factures-fournisseurs/proposition?${parametres}`);
}

export function enregistrerFactureFournisseur(saisie: SaisieFactureFournisseur, id?: number): Promise<FactureFournisseur> {
  return apiFetch(id ? `/factures-fournisseurs/${id}` : '/factures-fournisseurs', { method: id ? 'PUT' : 'POST', body: JSON.stringify(saisie), retries: 0 });
}

export function alignerCoutsFacture(id: number): Promise<{ message: string; facture: FactureFournisseur }> {
  return apiFetch(`/factures-fournisseurs/${id}/aligner-couts`, { method: 'POST', retries: 0 });
}

export function payerFactureFournisseur(id: number, paiement: { date_paiement: string; montant: number; mode: ModePaiementFournisseur; reference?: string; notes?: string }): Promise<FactureFournisseur> {
  return apiFetch(`/factures-fournisseurs/${id}/paiements`, { method: 'POST', body: JSON.stringify(paiement), retries: 0 });
}

export function annulerPaiementFournisseur(id: number, paiementId: number): Promise<FactureFournisseur> {
  return apiFetch(`/factures-fournisseurs/${id}/paiements/${paiementId}`, { method: 'DELETE', retries: 0 });
}

export function annulerFactureFournisseur(id: number, motif: string): Promise<FactureFournisseur> {
  return apiFetch(`/factures-fournisseurs/${id}/annuler`, { method: 'POST', body: JSON.stringify({ motif: motif || null }), retries: 0 });
}
