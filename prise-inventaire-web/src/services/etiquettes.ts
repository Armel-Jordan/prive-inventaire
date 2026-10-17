import { apiFetch } from '@/services/http';

/**
 * Étiquettes des produits (Paramètres → Étiquettes) : des formats (rouleau d'une étiquette par page pour une
 * imprimante d'étiquettes, ou planche A4 / Letter à découper) et ce que porte chaque étiquette ; l'impression en PDF,
 * produit par produit, en autant d'exemplaires que voulu.
 */

export type SupportEtiquette = 'rouleau' | 'planche';
export type ContenuEtiquette = 'entreprise' | 'description' | 'code_barres' | 'chiffres' | 'numero' | 'prix' | 'prix_taxes' | 'date';
export type PageEtiquettes = 'A4' | 'LETTER';

export interface FormatEtiquette {
  id: number;
  nom: string;
  support: SupportEtiquette;
  largeur_mm: number;
  hauteur_mm: number;
  /** Planche : la page, la grille et ses marges (mm). */
  page: PageEtiquettes | null;
  colonnes: number;
  rangees: number;
  marge_haut_mm: number;
  marge_gauche_mm: number;
  espace_colonnes_mm: number;
  espace_rangees_mm: number;
  contenu: ContenuEtiquette[];
  /** Taille du texte, en points. */
  taille_police: number;
  par_defaut: boolean;
  actif: boolean;
  ordre: number;
}

export type SaisieFormatEtiquette = Omit<FormatEtiquette, 'id' | 'ordre'>;

export interface CatalogueEtiquettes {
  formats: FormatEtiquette[];
  contenus: ContenuEtiquette[];
  /** Pages des planches : largeur et hauteur en millimètres. */
  pages: Record<PageEtiquettes, [number, number]>;
  /** Étiquettes au plus par impression. */
  maximum: number;
}

export interface LigneEtiquettes {
  produit_id: number;
  /** Vide : le code principal du produit, sinon son numéro. */
  code_barre_id?: number | null;
  copies: number;
}

export interface DemandeEtiquettes {
  format_id: number;
  lignes: LigneEtiquettes[];
  liste_prix_id?: number | null;
  groupe_taxe_id?: number | null;
  /** Planche entamée : la première position libre (1 : en haut à gauche). */
  depart?: number | null;
  langue?: 'fr' | 'en';
  /** Tracer le contour des étiquettes (essai sur papier ordinaire). */
  contours?: boolean;
}

/** Étiquettes par page d'un format. */
export function parPage(format: Pick<FormatEtiquette, 'support' | 'colonnes' | 'rangees'>): number {
  return format.support === 'planche' ? format.colonnes * format.rangees : 1;
}

export function getFormatsEtiquettes(): Promise<CatalogueEtiquettes> {
  return apiFetch('/etiquettes/formats');
}

export function creerFormatEtiquette(saisie: SaisieFormatEtiquette): Promise<FormatEtiquette> {
  return apiFetch('/etiquettes/formats', { method: 'POST', body: JSON.stringify(saisie), retries: 0 });
}

export function modifierFormatEtiquette(id: number, saisie: Partial<SaisieFormatEtiquette>): Promise<FormatEtiquette> {
  return apiFetch(`/etiquettes/formats/${id}`, { method: 'PUT', body: JSON.stringify(saisie), retries: 0 });
}

export function supprimerFormatEtiquette(id: number): Promise<{ message: string }> {
  return apiFetch(`/etiquettes/formats/${id}`, { method: 'DELETE', retries: 0 });
}

/**
 * Le PDF des étiquettes (jusqu'à quelques milliers : un délai plus long). La demande reste en JSON (Accept par défaut) :
 * une demande refusée revient avec ses erreurs de validation.
 */
export function imprimerEtiquettes(demande: DemandeEtiquettes): Promise<Blob> {
  return apiFetch('/etiquettes/imprimer', { method: 'POST', body: JSON.stringify(demande), retries: 0, responseType: 'blob', timeoutMs: 120000 });
}
