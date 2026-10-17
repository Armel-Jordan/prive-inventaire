import { ApiError, apiFetch } from '@/services/http';
import { ouvrirPdf } from '@/services/api';

/**
 * Gestion de caisse : caisses et leur session en cours, ouverture avec le fond, entrées et sorties d'argent,
 * fermeture (tiroir compté), historique des sessions et sommaire imprimable (module Ventes).
 */

export const MODES_CAISSE = ['especes', 'cheque', 'virement', 'carte', 'autre'] as const;
export type ModeCaisse = (typeof MODES_CAISSE)[number];

/** Chiffres d'une devise (null : celle de l'entreprise) ; compté et écart une fois la caisse fermée. */
export interface TotauxCaisse {
  devise: string | null;
  fond: number;
  encaissements: number;
  acomptes: number;
  remboursements: number;
  entrees: number;
  sorties: number;
  attendu: number;
  compte: number | null;
  ecart: number | null;
}

/** Chiffres d'un mode de paiement dans une devise. */
export interface LigneCaisse extends TotauxCaisse {
  mode: ModeCaisse;
}

export interface CaisseEtat {
  id: number;
  code: string;
  nom: string;
  actif: boolean;
  session: { id: number; ouverte_le: string; ouvreur: { id: number; nom: string } | null; totaux: TotauxCaisse[] } | null;
}

export type TypeOperation = 'fond' | 'encaissement' | 'acompte' | 'remboursement' | 'entree' | 'sortie';

/** Opération d'une session : son document d'origine, le client, le mode, le montant (sorties en négatif). */
export interface OperationCaisse {
  date: string;
  type: TypeOperation;
  document: string | null;
  facture_id: number | null;
  acompte_id: number | null;
  mouvement_id: number | null;
  client: string | null;
  mode: ModeCaisse;
  devise: string | null;
  montant: number;
  commis: string | null;
  note: string | null;
}

export interface SessionCaisse {
  id: number;
  caisse_id: number;
  statut: 'ouverte' | 'fermee';
  ouverte_le: string;
  fermee_le: string | null;
  note_fermeture: string | null;
  total_ecart: number | string | null;
  caisse: { id: number; code: string; nom: string } | null;
  ouvreur: { id: number; nom: string } | null;
  fermeur: { id: number; nom: string } | null;
  totaux: TotauxCaisse[];
}

export interface SommaireCaisse extends SessionCaisse {
  lignes: LigneCaisse[];
  operations: OperationCaisse[];
  par_usager: { commis: string | null; mode: ModeCaisse; devise: string | null; montant: number; nombre: number }[];
  sans_caisse: { operations: OperationCaisse[]; tronque: boolean };
}

/** Fuseau du navigateur : les jours de la période et les heures du sommaire sont ceux de l'utilisateur. */
export const fuseauUtilisateur = () => Intl.DateTimeFormat().resolvedOptions().timeZone;

export function getCaisses(): Promise<CaisseEtat[]> {
  return apiFetch('/caisses');
}

export function ouvrirCaisse(id: number, donnees: { fond?: number; mode?: ModeCaisse; note?: string }): Promise<SommaireCaisse> {
  return apiFetch(`/caisses/${id}/ouvrir`, { method: 'POST', body: JSON.stringify(donnees), retries: 0 });
}

export function getSessionsCaisse(params: { caisse_id?: number; du?: string; au?: string }): Promise<{ du: string; au: string; sessions: SessionCaisse[] }> {
  const requete = new URLSearchParams({ fuseau: fuseauUtilisateur() });
  if (params.caisse_id) requete.set('caisse_id', String(params.caisse_id));
  if (params.du) requete.set('du', params.du);
  if (params.au) requete.set('au', params.au);
  return apiFetch(`/caisses/sessions?${requete}`);
}

export function getSessionCaisse(id: number): Promise<SommaireCaisse> {
  return apiFetch(`/caisses/sessions/${id}`);
}

export function mouvementCaisse(sessionId: number, donnees: { type: 'entree' | 'sortie'; mode: ModeCaisse; devise?: string; montant: number; note?: string }): Promise<SommaireCaisse> {
  return apiFetch(`/caisses/sessions/${sessionId}/mouvements`, { method: 'POST', body: JSON.stringify(donnees), retries: 0 });
}

export function fermerCaisse(sessionId: number, donnees: { comptes: { mode: ModeCaisse; devise: string | null; montant: number }[]; note?: string }): Promise<SommaireCaisse> {
  return apiFetch(`/caisses/sessions/${sessionId}/fermer`, { method: 'POST', body: JSON.stringify(donnees), retries: 0 });
}

/** Sommaire de caisse d'une session fermée, heures dans le fuseau de l'utilisateur. */
export function imprimerSommaireCaisse(session: Pick<SessionCaisse, 'id' | 'caisse'>): Promise<void> {
  return ouvrirPdf(`/caisses/sessions/${session.id}/pdf?fuseau=${encodeURIComponent(fuseauUtilisateur())}`, `sommaire-caisse-${session.caisse?.code ?? session.id}.pdf`);
}

/** L'encaissement est refusé parce que la caisse du poste n'est pas ouverte (422 sur caisse_id). */
export function caisseFermee(erreur: unknown): boolean {
  if (!(erreur instanceof ApiError) || erreur.status !== 422) return false;
  const erreurs = (erreur.data as { errors?: Record<string, unknown> } | null)?.errors;
  return !!erreurs && 'caisse_id' in erreurs;
}
