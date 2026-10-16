import { apiFetch } from '@/services/http';

/** Sécurité des comptes : mot de passe oublié, changement de mot de passe, double authentification. */

export interface EtatDeuxFacteurs {
  active: boolean;
  active_le: string | null;
  codes_secours_restants: number;
  codes_secours?: string[];
}

export interface UtilisateurSecurite {
  id: number;
  nom: string;
  email: string;
  role: string;
  actif: boolean;
  deux_facteurs: boolean;
  deux_facteurs_active_le: string | null;
}

export async function demanderReinitialisation(donnees: { tenant_slug: string; email: string; langue: string }): Promise<{ message: string }> {
  return apiFetch('/auth/mot-de-passe-oublie', {
    method: 'POST',
    // L'API ne reprend cette origine que si elle fait partie des origines autorisées.
    body: JSON.stringify({ ...donnees, origine: window.location.origin }),
  });
}

export async function reinitialiserMotDePasse(donnees: { jeton: string; password: string; password_confirmation: string }): Promise<{ message: string }> {
  return apiFetch('/auth/reinitialiser-mot-de-passe', { method: 'POST', body: JSON.stringify(donnees) });
}

export async function changerMotDePasse(donnees: { mot_de_passe_actuel: string; password: string; password_confirmation: string }): Promise<{ message: string }> {
  return apiFetch('/auth/mot-de-passe', { method: 'PUT', body: JSON.stringify(donnees) });
}

export async function getEtatDeuxFacteurs(): Promise<EtatDeuxFacteurs> {
  return apiFetch('/auth/deux-facteurs');
}

export async function preparerDeuxFacteurs(password: string): Promise<{ secret: string; uri: string }> {
  return apiFetch('/auth/deux-facteurs/preparer', { method: 'POST', body: JSON.stringify({ password }) });
}

export async function activerDeuxFacteurs(code: string): Promise<EtatDeuxFacteurs> {
  return apiFetch('/auth/deux-facteurs/activer', { method: 'POST', body: JSON.stringify({ code }) });
}

export async function desactiverDeuxFacteurs(password: string): Promise<EtatDeuxFacteurs> {
  return apiFetch('/auth/deux-facteurs/desactiver', { method: 'POST', body: JSON.stringify({ password }) });
}

export async function regenererCodesSecours(password: string): Promise<EtatDeuxFacteurs> {
  return apiFetch('/auth/deux-facteurs/codes-secours', { method: 'POST', body: JSON.stringify({ password }) });
}

export async function getUtilisateursSecurite(): Promise<UtilisateurSecurite[]> {
  return apiFetch('/securite/utilisateurs');
}

export async function reinitialiserDeuxFacteursUtilisateur(id: number): Promise<{ message: string }> {
  return apiFetch(`/securite/utilisateurs/${id}/deux-facteurs`, { method: 'DELETE' });
}
