import type { Client, CoordonneesPassage } from '@/services/api';

/** Coordonnées du client de passage dans un formulaire de document (chaînes, vides par défaut). */
export type SaisiePassage = { [K in keyof CoordonneesPassage]-?: string };

export const PASSAGE_VIDE: SaisiePassage = { passage_nom: '', passage_telephone: '', passage_courriel: '', passage_adresse: '' };

/** Coordonnées d'un document à modifier, pour son formulaire. */
export function saisiePassage(document: CoordonneesPassage): SaisiePassage {
  return {
    passage_nom: document.passage_nom ?? '',
    passage_telephone: document.passage_telephone ?? '',
    passage_courriel: document.passage_courriel ?? '',
    passage_adresse: document.passage_adresse ?? '',
  };
}

/** Nom du client d'un document : celui noté pour le client de passage, sinon sa raison sociale. */
export function nomClient(document: CoordonneesPassage & { client?: Pick<Client, 'raison_sociale' | 'de_passage'> | null }): string | undefined {
  return document.client?.de_passage && document.passage_nom ? document.passage_nom : document.client?.raison_sociale;
}

/** Liste de clients avec la fiche du client de passage (ajoutée si elle n'y est pas encore). */
export function avecClient(clients: Client[], client: Client): Client[] {
  return clients.some(c => c.id === client.id) ? clients : [...clients, client].sort((a, b) => a.raison_sociale.localeCompare(b.raison_sociale));
}
