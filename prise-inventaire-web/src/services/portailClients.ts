import { apiFetch } from '@/services/http';

/** Espace client, côté entreprise : ouverture, réglages et accès des clients (module Ventes). */

/**
 * Adresse du site de l'espace client (le site des clients, distinct du back-office) : VITE_ESPACE_CLIENT_URL,
 * sinon le même hôte que le back-office sur le port 8081 (installation du droplet, voir docs/15).
 */
export const URL_ESPACE_CLIENT: string = (import.meta.env.VITE_ESPACE_CLIENT_URL
  || `${window.location.protocol}//${window.location.hostname}:8081`).replace(/\/+$/, '');

export type EtatAcces = 'actif' | 'invite' | 'suspendu';

export interface AccesPortail {
  id: number;
  client_id: number;
  nom: string;
  email: string;
  telephone: string | null;
  actif: boolean;
  client: { id: number; code: string; raison_sociale: string } | null;
  /** invite : lien envoyé, mot de passe pas encore choisi. */
  etat: EtatAcces;
  invitation_expire_le: string | null;
  derniere_connexion: string | null;
}

export interface ReglagesPortail {
  actif: boolean;
  message_accueil: string | null;
  /** Catégories de produits proposées ; null : tous les produits actifs. */
  categories: string[] | null;
}

export interface EtatPortailClients {
  parametres: ReglagesPortail;
  comptes: AccesPortail[];
  categories_disponibles: string[];
  /** Numérotation automatique des commandes client réglée : sans elle, le portail refuse les commandes. */
  commandes_pretes: boolean;
}

/** Lien d'accès : à transmettre au client si le courriel n'est pas parti. */
export interface ResultatInvitation {
  compte: AccesPortail;
  lien: string;
  courriel_envoye: boolean;
}

export type Langue = 'fr' | 'en';

export const getPortailClients = () => apiFetch<EtatPortailClients>('/portail-clients');

// L'adresse de l'espace client est transmise : l'API la retient pour les liens des courriels.
export const enregistrerReglagesPortail = (reglages: ReglagesPortail) =>
  apiFetch<{ message: string }>('/portail-clients/parametres', { method: 'PUT', body: JSON.stringify({ ...reglages, origine: URL_ESPACE_CLIENT }) });

export const creerAccesPortail = (acces: { client_id: number; nom: string; email: string; telephone: string | null; langue: Langue }) =>
  apiFetch<ResultatInvitation>('/portail-clients/comptes', { method: 'POST', body: JSON.stringify({ ...acces, origine: URL_ESPACE_CLIENT }) });

export const modifierAccesPortail = (id: number, acces: { nom: string; email: string; telephone: string | null; actif: boolean }) =>
  apiFetch<AccesPortail>(`/portail-clients/comptes/${id}`, { method: 'PUT', body: JSON.stringify(acces) });

export const renvoyerLienPortail = (id: number, langue: Langue) =>
  apiFetch<ResultatInvitation>(`/portail-clients/comptes/${id}/invitation`, { method: 'POST', body: JSON.stringify({ langue, origine: URL_ESPACE_CLIENT }) });

export const supprimerAccesPortail = (id: number) =>
  apiFetch<{ message: string }>(`/portail-clients/comptes/${id}`, { method: 'DELETE' });
