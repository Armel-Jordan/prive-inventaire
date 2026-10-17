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
    /** Sortie : le bon de livraison ou la facture de la vente au comptoir, et le client. */
    document: string | null;
    client: string | null;
  }[];
}

/** Rappel d'un lot : les clients qui l'ont reçu. */
export interface SortieLot {
  date: string | null;
  document: string | null;
  type: string;
  quantite: number;
  client: { id: number; code: string; raison_sociale: string; telephone: string | null; email: string | null } | null;
}

export interface NumeroSerieRegistre {
  id: number;
  numero: string;
  statut: string;
  produit: { id: number; numero: string; description: string } | null;
  fournisseur: string | null;
  recu_le: string | null;
  reception: { id: number; numero: string } | null;
  client: string | null;
  livre_le: string | null;
  bon_livraison: string | null;
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

export function getRappelLot(id: number): Promise<{ lot: LotRegistre; sorties: SortieLot[] }> {
  return apiFetch(`/lots/${id}/rappel`);
}

/** Rappel d'un lot en CSV (clients, documents, quantités, coordonnées). */
export async function exporterRappelLot(id: number, numero: string): Promise<void> {
  const fichier = await apiFetch<Blob>(`/lots/${id}/rappel?format=csv`, { responseType: 'blob', timeoutMs: 120000 });
  const lien = document.createElement('a');
  lien.href = URL.createObjectURL(fichier);
  lien.download = `rappel-lot-${numero}.csv`;
  lien.click();
  setTimeout(() => URL.revokeObjectURL(lien.href), 1000);
}

/**
 * Exemplaires proposés pour un retour : les lots du produit (sortis chez le client d'abord, avec ce qu'il a reçu ;
 * ou ceux qui ont du stock, le premier à périmer d'abord) et les numéros de série (livrés au client ; ou en stock).
 */
export interface ExemplairesProduit {
  produit_id: number;
  suivi: 'lot' | 'serie';
  lots: { id: number; numero: string; date_peremption: string | null; recu_par_client?: number | null; solde?: number }[];
  series: string[];
}

const produitsEnParametres = (produits: number[]) => produits.map(id => `produits[]=${id}`).join('&');

export function getExemplairesRetourClient(clientId: number, produits: number[]): Promise<ExemplairesProduit[]> {
  return apiFetch(`/retours-clients/exemplaires?client_id=${clientId}&${produitsEnParametres(produits)}`);
}

export function getExemplairesRetourFournisseur(produits: number[]): Promise<ExemplairesProduit[]> {
  return apiFetch(`/retours-fournisseurs/exemplaires?${produitsEnParametres(produits)}`);
}

export function getNumerosSerie(filtres: { recherche?: string; statut?: string; page?: number } = {}): Promise<Page<NumeroSerieRegistre>> {
  return apiFetch(`/numeros-serie?${parametres(filtres)}`);
}
