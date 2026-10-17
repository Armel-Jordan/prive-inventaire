import { apiFetch } from '@/services/http';
import type { PageResultats } from '@/services/api';

/**
 * Abonnements clients : produits facturés à intervalle régulier ; leurs échéances deviennent des factures (module
 * Ventes).
 */

export type Frequence = 'mensuelle' | 'trimestrielle' | 'semestrielle' | 'annuelle';
export const FREQUENCES: Frequence[] = ['mensuelle', 'trimestrielle', 'semestrielle', 'annuelle'];
export type StatutAbonnement = 'actif' | 'bientot_echu' | 'echu';
/** Variables de la description, remplacées par la période facturée. */
export const VARIABLES_ABONNEMENT = ['{mois}', '{annee}', '{debut_periode}', '{fin_periode}'];

export interface AbonnementClient {
  id: number;
  client_id: number;
  produit_id: number;
  description: string | null;
  quantite: number;
  prix_fixe: boolean;
  prix_unitaire: string | null;
  frequence: Frequence;
  jour_facturation: number;
  mois_facturation: number | null;
  jour_debut_periode: number;
  mois_debut_periode: number | null;
  periode_precedente: boolean;
  date_debut: string;
  date_fin: string;
  brouillon: boolean;
  notes: string | null;
  client?: { id: number; raison_sociale: string; devise: string | null } | null;
  produit?: { id: number; numero: string; description: string } | null;
  statut: StatutAbonnement;
  /** Prix du jour : le prix fixe, sinon le tarif du client aujourd'hui (dans sa devise). */
  prix_courant: number | null;
  total_mensuel: number | null;
  total_annuel: number | null;
  echeances_count?: number;
  echeances_en_retard?: number;
  prochaine_echeance?: string | null;
}

export interface EcheanceAbonnement {
  id: number;
  date_prevue: string;
  periode_debut: string;
  periode_fin: string;
  /** a_venir, en_retard, ou le statut de la facture (brouillon, emise, payee…). */
  statut: string;
  facture: { id: number; numero: string; statut: string; montant_ttc: string; devise: string | null } | null;
}

export type SaisieAbonnement = Pick<AbonnementClient, 'client_id' | 'produit_id' | 'description' | 'quantite' | 'prix_fixe' | 'frequence'
  | 'jour_facturation' | 'mois_facturation' | 'jour_debut_periode' | 'mois_debut_periode' | 'periode_precedente' | 'date_debut' | 'date_fin' | 'brouillon' | 'notes'>
  & { prix_unitaire: number | null };

export interface ApercuAbonnement {
  premiere_echeance: string | null;
  echeances: number;
  periode: [string, string] | null;
  description: string | null;
  prix: number | null;
}

export interface BilanFacturation {
  message: string;
  factures: { id: number; numero: string; client_id: number; date_facture: string; statut: string; montant_ttc: string; devise: string | null }[];
  echeances: number;
  erreurs: { client: string | null; date: string; message: string }[];
}

export function getAbonnements(params: { statut?: string; recherche?: string; produit_id?: number; page?: number }) {
  const requete = new URLSearchParams();
  Object.entries(params).forEach(([cle, valeur]) => { if (valeur !== undefined && valeur !== '') requete.append(cle, String(valeur)); });
  return apiFetch<PageResultats<AbonnementClient>>(`/abonnements-clients?${requete}`);
}

export const getAbonnement = (id: number) => apiFetch<AbonnementClient & { echeances: EcheanceAbonnement[] }>(`/abonnements-clients/${id}`);

export const creerAbonnement = (saisie: SaisieAbonnement) =>
  apiFetch<AbonnementClient & { echeances: EcheanceAbonnement[] }>('/abonnements-clients', { method: 'POST', body: JSON.stringify(saisie) });

export const modifierAbonnement = (id: number, saisie: SaisieAbonnement) =>
  apiFetch<AbonnementClient & { echeances: EcheanceAbonnement[] }>(`/abonnements-clients/${id}`, { method: 'PUT', body: JSON.stringify(saisie) });

export const supprimerAbonnement = (id: number) => apiFetch<{ message: string }>(`/abonnements-clients/${id}`, { method: 'DELETE' });

export const apercuAbonnement = (saisie: SaisieAbonnement) =>
  apiFetch<ApercuAbonnement>('/abonnements-clients/apercu', { method: 'POST', body: JSON.stringify(saisie) });

export const facturerAbonnements = (jusquau: string, clientId: number | null, emettre: boolean) =>
  apiFetch<BilanFacturation>('/abonnements-clients/facturer', { method: 'POST', body: JSON.stringify({ jusquau, client_id: clientId, emettre }) });

export const relierEcheance = (abonnementId: number, echeanceId: number, factureId: number) =>
  apiFetch<AbonnementClient & { echeances: EcheanceAbonnement[] }>(`/abonnements-clients/${abonnementId}/echeances/${echeanceId}/relier`,
    { method: 'POST', body: JSON.stringify({ facture_id: factureId }) });
