import { apiFetch } from '@/services/http';

/** Demandes de support et suggestions (phase 6.7) : côté entreprise, et côté éditeur (super-administrateur). */

export type TypeDemande = 'support' | 'suggestion';
export type PrioriteDemande = 'basse' | 'normale' | 'haute' | 'urgente';
export type StatutDemande = 'ouverte' | 'en_cours' | 'en_attente' | 'resolue' | 'fermee';

export const STATUTS_DEMANDE: StatutDemande[] = ['ouverte', 'en_cours', 'en_attente', 'resolue', 'fermee'];
export const PRIORITES_DEMANDE: PrioriteDemande[] = ['basse', 'normale', 'haute', 'urgente'];

export interface DemandeSupport {
  id: number;
  type: TypeDemande;
  sujet: string;
  priorite: PrioriteDemande;
  statut: StatutDemande;
  /** Écran d'où la demande est partie. */
  ecran: string | null;
  /** Qui a écrit en dernier. */
  dernier_auteur: 'usager' | 'editeur' | null;
  auteur: string | null;
  dernier_message_le: string | null;
  resolue_le: string | null;
  created_at: string | null;
}

export interface MessageSupport {
  id: number;
  auteur: 'usager' | 'editeur';
  nom: string | null;
  message: string;
  created_at: string | null;
}

export interface DetailDemande extends DemandeSupport {
  description: string;
  messages: MessageSupport[];
}

/** Demande vue par l'éditeur : avec son entreprise et l'adresse de l'usager. */
export interface DemandeEditeur extends DetailDemande {
  tenant_id: number;
  entreprise: string | null;
  auteur_email: string | null;
}

export function getDemandesSupport(filtres: { statut?: string; type?: string } = {}): Promise<{ toutes: boolean; demandes: DemandeSupport[] }> {
  const p = new URLSearchParams(Object.entries(filtres).filter(([, v]) => v) as [string, string][]);
  return apiFetch(`/support?${p}`);
}

export function getDemandeSupport(id: number): Promise<DetailDemande> {
  return apiFetch(`/support/${id}`);
}

export function creerDemandeSupport(demande: { type: TypeDemande; sujet: string; description: string; priorite: PrioriteDemande; ecran: string | null }): Promise<DetailDemande> {
  return apiFetch('/support', { method: 'POST', body: JSON.stringify(demande) });
}

export function repondreDemandeSupport(id: number, message: string): Promise<DetailDemande> {
  return apiFetch(`/support/${id}/messages`, { method: 'POST', body: JSON.stringify({ message }) });
}

export function fermerDemandeSupport(id: number): Promise<DetailDemande> {
  return apiFetch(`/support/${id}/fermer`, { method: 'POST' });
}

/** Appels de l'éditeur : le jeton du super-administrateur, et non celui d'un compte d'entreprise. */
const editeur = (jeton: string) => ({ Authorization: `Bearer ${jeton}` });

export function getDemandesEditeur(jeton: string, filtres: { statut?: string; type?: string; actives?: boolean } = {}): Promise<{ demandes: DemandeEditeur[]; par_statut: Partial<Record<StatutDemande, number>> }> {
  const p = new URLSearchParams();
  if (filtres.statut) p.set('statut', filtres.statut);
  if (filtres.type) p.set('type', filtres.type);
  if (filtres.actives) p.set('actives', '1');
  return apiFetch(`/super-admin/support?${p}`, { headers: editeur(jeton) });
}

export function getDemandeEditeur(jeton: string, id: number): Promise<DemandeEditeur> {
  return apiFetch(`/super-admin/support/${id}`, { headers: editeur(jeton) });
}

export function repondreDemandeEditeur(jeton: string, id: number, message: string, statut: StatutDemande | null): Promise<DemandeEditeur> {
  return apiFetch(`/super-admin/support/${id}/messages`, { method: 'POST', headers: editeur(jeton), body: JSON.stringify({ message, statut }) });
}

export function modifierDemandeEditeur(jeton: string, id: number, changements: { statut?: StatutDemande; priorite?: PrioriteDemande }): Promise<DemandeEditeur> {
  return apiFetch(`/super-admin/support/${id}`, { method: 'PUT', headers: editeur(jeton), body: JSON.stringify(changements) });
}
