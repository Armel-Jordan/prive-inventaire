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
  /** fixe : la quantité ; releve : la consommation relevée sur la période, au-delà de la quantité incluse. */
  mode_quantite: 'fixe' | 'releve';
  quantite: number;
  quantite_incluse: number;
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
  releves_count?: number;
}

/** Relevé de consommation d'un abonnement à l'usage ; `facture` : sa période est déjà facturée (il est figé). */
export interface ReleveAbonnement { id: number; date_releve: string; quantite: number; note: string | null; facture: boolean }

export interface EcheanceAbonnement {
  id: number;
  date_prevue: string;
  periode_debut: string;
  periode_fin: string;
  /** a_venir, en_retard, ou le statut de la facture (brouillon, emise, payee…). */
  statut: string;
  facture: { id: number; numero: string; statut: string; montant_ttc: string; devise: string | null } | null;
}

export type SaisieAbonnement = Pick<AbonnementClient, 'client_id' | 'produit_id' | 'description' | 'mode_quantite' | 'quantite' | 'quantite_incluse' | 'prix_fixe' | 'frequence'
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
  /** Échéances à l'usage closes sans facture (consommation couverte par la quantité incluse). */
  sans_facture?: number;
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

export const getReleves = (abonnementId: number) => apiFetch<ReleveAbonnement[]>(`/abonnements-clients/${abonnementId}/releves`);

export const ajouterReleve = (abonnementId: number, releve: { date_releve: string; quantite: number; note: string | null }) =>
  apiFetch<ReleveAbonnement[]>(`/abonnements-clients/${abonnementId}/releves`, { method: 'POST', body: JSON.stringify(releve) });

export const supprimerReleve = (abonnementId: number, releveId: number) =>
  apiFetch<ReleveAbonnement[]>(`/abonnements-clients/${abonnementId}/releves/${releveId}`, { method: 'DELETE' });

/** Import CSV (client, produit, date, quantité, note) : tout ou rien ; en cas d'erreur, l'API répond 422 avec `erreurs`. */
export function importerReleves(fichier: File) {
  const formulaire = new FormData();
  formulaire.append('fichier', fichier);
  return apiFetch<{ importes: number; erreurs: string[]; message: string }>('/abonnements-clients/releves/import', { method: 'POST', body: formulaire, timeoutMs: 120000 });
}

export const relierEcheance = (abonnementId: number, echeanceId: number, factureId: number) =>
  apiFetch<AbonnementClient & { echeances: EcheanceAbonnement[] }>(`/abonnements-clients/${abonnementId}/echeances/${echeanceId}/relier`,
    { method: 'POST', body: JSON.stringify({ facture_id: factureId }) });
