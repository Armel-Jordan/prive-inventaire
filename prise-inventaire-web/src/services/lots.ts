import { apiFetch } from '@/services/http';

/** Lots et numéros de série des produits suivis (phase 2.8) : registre, péremptions, provenance. */

export type EtatLot = 'perime' | 'a_surveiller' | 'ok';

export interface LotRegistre {
  id: number;
  numero: string;
  produit: { id: number; numero: string; description: string; mesure: string | null } | null;
  date_peremption: string | null;
  /** Jours avant la péremption (négatif : périmé depuis). */
  jours_restants: number | null;
  etat: EtatLot | null;
  fournisseur: string | null;
  recu: number;
  solde: number;
  premiere_reception: string | null;
}

export interface DetailLot extends LotRegistre {
  notes: string | null;
  mouvements: {
    id: number;
    type: string;
    quantite: number;
    date: string | null;
    reception: { id: number; numero: string } | null;
    commande: string | null;
    secteur: string | null;
  }[];
}

export interface NumeroSerieRegistre {
  id: number;
  numero: string;
  statut: string;
  produit: { id: number; numero: string; description: string } | null;
  fournisseur: string | null;
  recu_le: string | null;
  reception: { id: number; numero: string } | null;
}

export interface Page<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}

export interface FiltresLots {
  recherche?: string;
  etat?: string;
  avec_stock?: boolean;
  page?: number;
}

const parametres = (filtres: object) => {
  const p = new URLSearchParams();
  Object.entries(filtres).forEach(([cle, valeur]) => {
    if (valeur === undefined || valeur === '' || valeur === false) return;
    p.set(cle, valeur === true ? '1' : String(valeur));
  });
  return p.toString();
};

export function getLots(filtres: FiltresLots = {}): Promise<Page<LotRegistre> & { jours_alerte: number }> {
  return apiFetch(`/lots?${parametres(filtres)}`);
}

export function getLot(id: number): Promise<DetailLot> {
  return apiFetch(`/lots/${id}`);
}

export function modifierLot(id: number, donnees: { date_peremption?: string | null; notes?: string | null }): Promise<DetailLot> {
  return apiFetch(`/lots/${id}`, { method: 'PUT', body: JSON.stringify(donnees) });
}

/** Registre des lots en CSV, téléchargé avec l'en-tête d'authentification. */
export async function exporterLots(filtres: FiltresLots = {}): Promise<void> {
  const fichier = await apiFetch<Blob>(`/lots?${parametres({ ...filtres, page: undefined, format: 'csv' })}`, { responseType: 'blob', timeoutMs: 120000 });
  const lien = document.createElement('a');
  lien.href = URL.createObjectURL(fichier);
  lien.download = 'lots.csv';
  lien.click();
  setTimeout(() => URL.revokeObjectURL(lien.href), 1000);
}

export function getNumerosSerie(filtres: { recherche?: string; statut?: string; page?: number } = {}): Promise<Page<NumeroSerieRegistre>> {
  return apiFetch(`/numeros-serie?${parametres(filtres)}`);
}
