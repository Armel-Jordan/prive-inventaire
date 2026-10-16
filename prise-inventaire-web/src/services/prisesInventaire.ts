import { apiFetch } from '@/services/http';

/** Prises d'inventaire : sessions de comptage par-dessus les scans, écarts, validation, inventaire officiel. */

export type StatutPrise = 'en_cours' | 'validee' | 'annulee';
export type StatutLigne = 'nouveau' | 'non_compte' | 'ecart' | 'conforme';

export interface PriseInventaire {
  id: number;
  numero: string;
  libelle: string;
  statut: StatutPrise;
  tous_secteurs: boolean;
  date_debut: string;
  date_fin: string | null;
  notes: string | null;
  cloturee_le: string | null;
  secteurs: { id: number; code: string; nom: string }[];
  createur?: { id: number; nom: string } | null;
  cloture_par?: { id: number; nom: string } | null;
  lignes_count?: number;
}

export interface AvancementSecteur {
  secteur_id: number | null;
  code: string;
  nom: string | null;
  nb_scans: number;
  nb_produits: number;
  nb_employes: number;
  dernier_scan: string | null;
  reference: { id: number; numero: string; date: string | null } | null;
}

export interface LignePrise {
  secteur_id: number | null;
  secteur: string;
  produit_numero: string;
  description: string | null;
  quantite_comptee: number;
  nb_scans: number;
  quantite_reference: number | null;
  reference_prise_id: number | null;
  entrees_depuis: number | null;
  sorties_depuis: number | null;
  quantite_retenue: number;
  ecart: number | null;
  raison: string | null;
  corrigee: boolean;
  statut: StatutLigne;
}

export interface DetailPrise {
  prise: PriseInventaire;
  avancement: AvancementSecteur[];
  resume: Record<StatutLigne | 'lignes' | 'corrigees', number> & { quantite_comptee: number; quantite_retenue: number };
}

export interface Page<T> {
  data: T[];
  total: number;
  current_page: number;
  last_page: number;
}

export interface LigneOfficielle {
  secteur_id: number | null;
  secteur: string;
  produit_numero: string;
  description: string | null;
  quantite: number;
  prise: { id: number; numero: string; date: string | null } | null;
}

const requete = (params: Record<string, string | number | undefined>) => {
  const query = new URLSearchParams();
  Object.entries(params).forEach(([cle, valeur]) => { if (valeur !== undefined && valeur !== '') query.append(cle, String(valeur)); });
  return query.toString();
};

export async function getPrisesInventaire(): Promise<PriseInventaire[]> {
  return apiFetch('/prises-inventaire');
}

export async function creerPriseInventaire(donnees: { libelle: string; tous_secteurs: boolean; secteur_ids: number[]; date_debut?: string; notes?: string }): Promise<PriseInventaire> {
  return apiFetch('/prises-inventaire', { method: 'POST', body: JSON.stringify(donnees) });
}

export async function getPriseInventaire(id: number): Promise<DetailPrise> {
  return apiFetch(`/prises-inventaire/${id}`);
}

export async function getLignesPrise(id: number, filtres: { statut?: string; secteur?: string; recherche?: string; page?: number }): Promise<Page<LignePrise>> {
  return apiFetch(`/prises-inventaire/${id}/lignes?${requete(filtres)}`);
}

export async function corrigerLignePrise(id: number, donnees: { secteur: string; produit_numero: string; quantite_retenue: number | null; raison?: string | null }): Promise<LignePrise> {
  return apiFetch(`/prises-inventaire/${id}/corrections`, { method: 'PUT', body: JSON.stringify(donnees) });
}

export async function validerPriseInventaire(id: number): Promise<PriseInventaire> {
  return apiFetch(`/prises-inventaire/${id}/valider`, { method: 'POST' });
}

export async function annulerPriseInventaire(id: number): Promise<PriseInventaire> {
  return apiFetch(`/prises-inventaire/${id}/annuler`, { method: 'POST' });
}

/** Rapport d'écarts en CSV, téléchargé avec l'en-tête d'authentification. */
export async function exporterPriseInventaire(id: number, numero: string, filtres: { statut?: string; secteur?: string; recherche?: string }): Promise<void> {
  const fichier = await apiFetch<Blob>(`/prises-inventaire/${id}/export?${requete(filtres)}`, { responseType: 'blob' });
  const lien = document.createElement('a');
  lien.href = URL.createObjectURL(fichier);
  lien.download = `${numero}-ecarts.csv`;
  lien.click();
  setTimeout(() => URL.revokeObjectURL(lien.href), 1000);
}

export async function getInventaireOfficiel(filtres: { secteur_id?: number; recherche?: string; page?: number }): Promise<Page<LigneOfficielle>> {
  return apiFetch(`/prises-inventaire/officiel?${requete(filtres)}`);
}
