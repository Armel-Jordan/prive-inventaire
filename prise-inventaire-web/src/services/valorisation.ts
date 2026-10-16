import { apiFetch } from '@/services/http';

/** Valorisation de l'inventaire officiel (dernières prises validées) au coût d'achat ou au prix de vente. */

export type MethodeValorisation = 'cout_moyen' | 'dernier_achat' | 'prix_vente';
export const METHODES_VALORISATION: MethodeValorisation[] = ['cout_moyen', 'dernier_achat', 'prix_vente'];

export interface ProduitValorise {
  numero: string;
  description: string | null;
  categorie: string | null;
  quantite: number;
  cout_unitaire: number | null;
  reference: string | null;
  valeur: number | null;
  secteurs: string[];
}

export interface SecteurValorise {
  secteur_id: number | null;
  secteur: string;
  valeur: number;
  nb_produits: number;
  nb_sans_cout: number;
  prise: { id: number; numero: string; date: string | null } | null;
}

export interface Valorisation {
  methode: MethodeValorisation;
  devise: string;
  total: number;
  nb_produits: number;
  nb_sans_cout: number;
  produits: ProduitValorise[];
  secteurs: SecteurValorise[];
}

function requete(filtres: { methode: MethodeValorisation; secteur_id?: number; format?: string }): string {
  const parametres = new URLSearchParams({ methode: filtres.methode });
  if (filtres.secteur_id) parametres.set('secteur_id', String(filtres.secteur_id));
  if (filtres.format) parametres.set('format', filtres.format);
  return parametres.toString();
}

export async function getValorisation(methode: MethodeValorisation, secteurId?: number): Promise<Valorisation> {
  return apiFetch(`/prises-inventaire/valorisation?${requete({ methode, secteur_id: secteurId })}`, { timeoutMs: 120000 });
}

/** Export CSV, téléchargé avec l'en-tête d'authentification. */
export async function exporterValorisation(methode: MethodeValorisation, secteurId?: number): Promise<void> {
  const fichier = await apiFetch<Blob>(`/prises-inventaire/valorisation?${requete({ methode, secteur_id: secteurId, format: 'csv' })}`, { responseType: 'blob', timeoutMs: 120000 });
  const lien = document.createElement('a');
  lien.href = URL.createObjectURL(fichier);
  lien.download = `valorisation-inventaire-${methode}.csv`;
  lien.click();
  setTimeout(() => URL.revokeObjectURL(lien.href), 1000);
}
