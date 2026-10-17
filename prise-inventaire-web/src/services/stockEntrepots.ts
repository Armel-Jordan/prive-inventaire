import { apiFetch } from '@/services/http';

/**
 * Stock par entrepôt (multi-entrepôts) : la somme des scans de chaque produit, répartie selon l'entrepôt de leur
 * secteur ; les scans d'un secteur sans entrepôt (ou inconnu) sont « sans entrepôt ».
 */

export interface LigneStockEntrepots {
  produit_id: number | null;
  numero: string;
  description: string | null;
  mesure: string | null;
  total: number;
  /** Quantité par entrepôt (identifiant), seulement là où il y en a. */
  entrepots: Record<string, number>;
  sans_entrepot: number;
}

export interface StockEntrepots {
  /** Colonnes : les entrepôts actifs, et ceux (désactivés, retirés) qui gardent du stock. */
  entrepots: { id: number; code: string; nom: string; actif: boolean }[];
  produits: LigneStockEntrepots[];
  totaux: { entrepots: Record<string, number>; sans_entrepot: number; total: number };
}

const requete = (filtres: { recherche?: string; entrepot_id?: number }, format?: 'csv') => {
  const parametres = new URLSearchParams();
  if (filtres.recherche) parametres.set('recherche', filtres.recherche);
  if (filtres.entrepot_id) parametres.set('entrepot_id', String(filtres.entrepot_id));
  if (format) parametres.set('format', format);
  return `/stock-entrepots?${parametres}`;
};

export function getStockEntrepots(filtres: { recherche?: string; entrepot_id?: number } = {}): Promise<StockEntrepots> {
  return apiFetch(requete(filtres));
}

/** Le même état en CSV, téléchargé avec l'en-tête d'authentification. */
export async function telechargerStockEntrepots(filtres: { recherche?: string; entrepot_id?: number } = {}): Promise<void> {
  const fichier = await apiFetch<Blob>(requete(filtres, 'csv'), { responseType: 'blob', timeoutMs: 120000 });
  const lien = document.createElement('a');
  lien.href = URL.createObjectURL(fichier);
  lien.download = 'stock-par-entrepot.csv';
  lien.click();
  setTimeout(() => URL.revokeObjectURL(lien.href), 1000);
}
