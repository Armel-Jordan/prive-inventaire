import { apiFetch } from '@/services/http';
import type { PageResultats } from '@/services/api';
import type { Frequence } from '@/services/abonnementsClients';

/**
 * Contrats de location : produits loués à un client, prix de contrat facturé une fois, loyer par période, retours,
 * renouvellement (module Ventes).
 */

export interface LigneContrat {
  id: number;
  produit_id: number;
  quantite: number;
  quantite_exemptee: number;
  quantite_retournee: number;
  date_retour: string | null;
  prix_contrat: string;
  prix_location: string;
  facture_id: number | null;
  produit?: { id: number; numero: string; description: string } | null;
  facture?: { id: number; numero: string; statut: string } | null;
}

export interface ContratLocation {
  id: number;
  numero: string;
  client_id: number;
  type_location_id: number | null;
  date_debut: string;
  date_fin: string;
  frequence: Frequence;
  jour_facturation: number;
  a_echoir: boolean;
  prochaine_facturation: string | null;
  derniere_facturation: string | null;
  date_facturation_contrat: string | null;
  actif: boolean;
  renouvellement_auto: boolean;
  taux_contrat: string;
  taux_location: string;
  contrat_precedent_id: number | null;
  renouvele_par_id: number | null;
  notes: string | null;
  client?: { id: number; raison_sociale: string; devise: string | null } | null;
  type?: { id: number; code: string; nom: string } | null;
  lignes: LigneContrat[];
  renouvele_par?: { id: number; numero: string } | null;
  precedent?: { id: number; numero: string } | null;
  /** Loyer d'une période : unités facturables × loyer. */
  loyer_periode: number;
  /** Lignes dont le prix de contrat reste à facturer. */
  a_facturer: number;
  unites_louees: number;
  echu: boolean;
  factures?: { id: number; numero: string; date_facture: string; statut: string; montant_ttc: string; devise: string | null }[];
}

export interface SaisieContrat {
  client_id: number;
  type_location_id: number | null;
  date_debut: string;
  date_fin: string;
  frequence: Frequence;
  jour_facturation: number;
  a_echoir: boolean;
  prochaine_facturation: string | null;
  actif: boolean;
  renouvellement_auto: boolean;
  taux_contrat: number;
  taux_location: number;
  notes: string | null;
  lignes: { id?: number; produit_id: number; quantite: number; quantite_exemptee: number; prix_contrat: number; prix_location: number }[];
}

export interface BilanLoyers {
  message: string;
  factures: { id: number; numero: string; client_id: number; contrat_location_id: number; date_facture: string; statut: string; montant_ttc: string; devise: string | null }[];
  erreurs: { contrat: string; client: string | null; message: string }[];
}

export function getContrats(params: { statut?: string; recherche?: string; fin_avant?: string; page?: number }) {
  const requete = new URLSearchParams();
  Object.entries(params).forEach(([cle, valeur]) => { if (valeur !== undefined && valeur !== '') requete.append(cle, String(valeur)); });
  return apiFetch<PageResultats<ContratLocation>>(`/contrats-location?${requete}`);
}

export const getContrat = (id: number) => apiFetch<ContratLocation>(`/contrats-location/${id}`);

export const creerContrat = (saisie: SaisieContrat) => apiFetch<ContratLocation>('/contrats-location', { method: 'POST', body: JSON.stringify(saisie) });

export const modifierContrat = (id: number, saisie: SaisieContrat) =>
  apiFetch<ContratLocation>(`/contrats-location/${id}`, { method: 'PUT', body: JSON.stringify(saisie) });

export const supprimerContrat = (id: number) => apiFetch<{ message: string }>(`/contrats-location/${id}`, { method: 'DELETE' });

export const facturerContrat = (id: number) =>
  apiFetch<{ message: string; facture: { id: number; numero: string } }>(`/contrats-location/${id}/facturer`, { method: 'POST' });

export const facturerLoyers = (jusquau: string, clientId: number | null, emettre: boolean) =>
  apiFetch<BilanLoyers>('/contrats-location/facturer-loyers', { method: 'POST', body: JSON.stringify({ jusquau, client_id: clientId, emettre }) });

export const retourLigne = (contratId: number, ligneId: number, quantite: number, dateRetour: string) =>
  apiFetch<ContratLocation>(`/contrats-location/${contratId}/lignes/${ligneId}/retour`, { method: 'POST', body: JSON.stringify({ quantite, date_retour: dateRetour }) });

export const renouvelerContrat = (id: number) => apiFetch<ContratLocation>(`/contrats-location/${id}/renouveler`, { method: 'POST' });
