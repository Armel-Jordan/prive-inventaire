import { apiFetch } from '@/services/http';

/**
 * Suggestions d'achat : stock sous le minimum et reliquats clients à commander, suggestion manuelle depuis un produit,
 * acceptation, commandes fournisseur des suggestions acceptées (module Achats).
 */

export type SourceSuggestion = 'inventaire' | 'reliquat' | 'manuelle';
export type StatutSuggestion = 'en_attente' | 'acceptee' | 'commandee';

export interface SuggestionAchat {
  id: number;
  produit_id: number;
  fournisseur_id: number | null;
  source: SourceSuggestion;
  reliquat_id: number | null;
  quantite_suggeree: number;
  quantite: number | null;
  statut: StatutSuggestion;
  remarque: string | null;
  commis_nom: string | null;
  produit: { id: number; numero: string; description: string; mesure: string | null; seuil_alerte: string | number | null; stock_maximum: string | number | null } | null;
  fournisseur: { id: number; code: string; raison_sociale: string; devise: string | null } | null;
  reliquat: {
    id: number;
    quantite: number;
    date_requise: string | null;
    client: { id: number; code: string; raison_sociale: string } | null;
    commande_client: { id: number; numero: string } | null;
  } | null;
  ligne_commande: { id: number; commande: { id: number; numero: string; statut: string } | null } | null;
  disponible: number | null;
  en_commande: number | null;
  vendu_12_mois: number;
  dernier_cout: number | null;
}

export function getSuggestionsAchat(statut: 'ouvertes' | 'commandees' = 'ouvertes'): Promise<SuggestionAchat[]> {
  return apiFetch(`/suggestions-achat?statut=${statut}`);
}

export function genererSuggestions(options: { inventaire: boolean; reliquats: boolean; avec_commandes: boolean; fournisseur_id?: number }): Promise<{ message: string; nombre: number }> {
  return apiFetch('/suggestions-achat/generer', { method: 'POST', body: JSON.stringify(options), retries: 0 });
}

export function suggererAchat(donnees: { produit_id: number; quantite: number; remarque?: string; forcer?: boolean }): Promise<SuggestionAchat> {
  return apiFetch('/suggestions-achat', { method: 'POST', body: JSON.stringify(donnees), retries: 0 });
}

export function modifierSuggestion(id: number, donnees: { quantite?: number; fournisseur_id?: number; remarque?: string | null }): Promise<SuggestionAchat> {
  return apiFetch(`/suggestions-achat/${id}`, { method: 'PUT', body: JSON.stringify(donnees), retries: 0 });
}

export function accepterSuggestions(ids: number[], accepte: boolean): Promise<{ nombre: number }> {
  return apiFetch('/suggestions-achat/accepter', { method: 'POST', body: JSON.stringify({ ids, accepte }), retries: 0 });
}

export function retirerSuggestion(id: number): Promise<void> {
  return apiFetch(`/suggestions-achat/${id}`, { method: 'DELETE', retries: 0 });
}

export function commanderSuggestions(fusionner: boolean): Promise<{ message: string; commandes: { id: number; numero: string }[] }> {
  return apiFetch('/suggestions-achat/commander', { method: 'POST', body: JSON.stringify({ fusionner }), retries: 0 });
}
