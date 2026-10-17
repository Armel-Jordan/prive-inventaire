import { apiFetch } from '@/services/http';

/** Bibliothèque des rapports : catalogue (selon les droits du compte), exécution, fichiers CSV, Excel et PDF. */

export type TypeParametre = 'date' | 'entier' | 'choix' | 'client' | 'fournisseur' | 'categorie';
export type TypeColonne = 'texte' | 'date' | 'entier' | 'quantite' | 'montant' | 'pourcentage';
export type FormatRapport = 'csv' | 'xlsx' | 'pdf';

export interface ParametreRapport {
  cle: string;
  type: TypeParametre;
  libelle: string;
  defaut?: string | number;
  min?: number;
  max?: number;
  options?: { valeur: string; libelle: string }[];
}

export interface DefinitionRapport {
  code: string;
  categorie: string;
  titre: string;
  description: string;
  parametres: ParametreRapport[];
}

export interface ResultatRapport {
  rapport: DefinitionRapport;
  valeurs: Record<string, string | number | null>;
  colonnes: { cle: string; libelle: string; type: TypeColonne; total: boolean }[];
  lignes: Record<string, string | number | null>[];
  totaux: Record<string, number>;
  genere_le: string;
}

const requete = (parametres: Record<string, string>, langue: string, format?: FormatRapport) => {
  const p = new URLSearchParams({ langue });
  Object.entries(parametres).forEach(([cle, valeur]) => { if (valeur !== '') p.set(cle, valeur); });
  if (format) {
    p.set('format', format);
    // Heure d'établissement du PDF dans le fuseau de l'utilisateur.
    p.set('fuseau', Intl.DateTimeFormat().resolvedOptions().timeZone);
  }
  return p.toString();
};

export interface CatalogueRapports {
  categories: { code: string; rapports: DefinitionRapport[] }[];
  /** Catégories de produits, pour les rapports filtrés par catégorie. */
  categories_produits: string[];
}

export function getCatalogueRapports(langue: string): Promise<CatalogueRapports> {
  return apiFetch(`/rapports/bibliotheque?langue=${langue}`);
}

export function executerRapport(code: string, parametres: Record<string, string>, langue: string): Promise<ResultatRapport> {
  return apiFetch(`/rapports/bibliotheque/${code}?${requete(parametres, langue)}`, { timeoutMs: 120000 });
}

/** Le rapport en fichier, téléchargé avec l'en-tête d'authentification. */
export async function telechargerRapport(code: string, parametres: Record<string, string>, langue: string, format: FormatRapport): Promise<void> {
  const fichier = await apiFetch<Blob>(`/rapports/bibliotheque/${code}?${requete(parametres, langue, format)}`, { responseType: 'blob', timeoutMs: 120000 });
  const lien = document.createElement('a');
  lien.href = URL.createObjectURL(fichier);
  lien.download = `${code}-${new Date().toISOString().slice(0, 10)}.${format}`;
  lien.click();
  setTimeout(() => URL.revokeObjectURL(lien.href), 1000);
}
