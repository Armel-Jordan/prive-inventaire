import type { Employe, Produit, Secteur, InventaireScan, AdminUser } from '@/types';
import { apiFetch, getAuthHeaders, API_BASE_URL, type ApiOptions } from './http';

// Réexport pour compatibilité (des composants importent getAuthHeaders d'ici).
export { getAuthHeaders };

// Mock data pour le développement (à remplacer par les vrais appels API)
// Mettre à false quand l'API Laravel est prête
const MOCK_MODE = import.meta.env.VITE_MOCK_MODE === 'true';

const mockEmployes: Employe[] = [
  { id: 1, numero: 'E001', nom: 'Jean Dupont' },
  { id: 2, numero: 'E002', nom: 'Marie Martin' },
  { id: 3, numero: 'E003', nom: 'Pierre Tremblay' },
];

const mockProduits: Produit[] = [
  { id: 1, numero: 'P001', description: 'Vis 10mm', mesure: 'UN', type: 'PIECE' },
  { id: 2, numero: 'P002', description: 'Boulon 15mm', mesure: 'UN', type: 'PIECE' },
  { id: 3, numero: 'P003', description: 'Huile moteur 5L', mesure: 'L', type: 'LIQUIDE' },
];

const mockSecteurs: Secteur[] = [
  { id: 1, code: 'A1', nom: 'Entrepôt A - Zone 1', description: 'Zone de stockage principal' },
  { id: 2, code: 'A2', nom: 'Entrepôt A - Zone 2', description: 'Zone de pièces détachées' },
  { id: 3, code: 'B1', nom: 'Entrepôt B - Zone 1', description: 'Zone de produits finis' },
];

const mockScans: InventaireScan[] = [
  { id: 1, numero: 'P001', type: 'PIECE', quantite: 150, unite_mesure: 'UN', employe: 'E001', secteur: 'A1', date_saisie: '2026-03-01T10:30:00', scanneur: 'SCAN001' },
  { id: 2, numero: 'P002', type: 'PIECE', quantite: 75, unite_mesure: 'UN', employe: 'E001', secteur: 'A1', date_saisie: '2026-03-01T10:35:00', scanneur: 'SCAN001' },
  { id: 3, numero: 'P003', type: 'LIQUIDE', quantite: 20, unite_mesure: 'L', employe: 'E002', secteur: 'B1', date_saisie: '2026-03-01T11:00:00', scanneur: 'SCAN002' },
];

async function fetchApi<T>(endpoint: string, options?: ApiOptions): Promise<T> {
  // Délègue au client central résilient (timeout, retry idempotent, erreurs typées).
  return apiFetch<T>(endpoint, options);
}

// === EMPLOYES ===
export async function getEmployes(): Promise<Employe[]> {
  if (MOCK_MODE) return mockEmployes;
  return fetchApi<Employe[]>('/employes');
}

export async function createEmploye(employe: Omit<Employe, 'id'>): Promise<Employe> {
  if (MOCK_MODE) {
    const newEmploye = { ...employe, id: mockEmployes.length + 1 } as Employe;
    mockEmployes.push(newEmploye);
    return newEmploye;
  }
  const response = await fetchApi<{ employe: Employe }>('/employes', {
    method: 'POST',
    body: JSON.stringify(employe),
  });
  return response.employe;
}

export async function updateEmploye(id: number, employe: Partial<Employe>): Promise<Employe> {
  if (MOCK_MODE) {
    const index = mockEmployes.findIndex(e => e.id === id);
    if (index !== -1) {
      mockEmployes[index] = { ...mockEmployes[index], ...employe };
      return mockEmployes[index];
    }
    throw new Error('Employé non trouvé');
  }
  const response = await fetchApi<{ employe: Employe }>(`/employes/${id}`, {
    method: 'PUT',
    body: JSON.stringify(employe),
  });
  return response.employe;
}

export async function deleteEmploye(id: number): Promise<void> {
  if (MOCK_MODE) {
    const index = mockEmployes.findIndex(e => e.id === id);
    if (index !== -1) mockEmployes.splice(index, 1);
    return;
  }
  await fetchApi(`/employes/${id}`, { method: 'DELETE' });
}

// === PRODUITS ===
export async function getProduits(): Promise<Produit[]> {
  if (MOCK_MODE) return mockProduits;
  return fetchApi<Produit[]>('/produits');
}

export async function createProduit(produit: Omit<Produit, 'id'>): Promise<Produit> {
  if (MOCK_MODE) {
    const newProduit = { ...produit, id: mockProduits.length + 1 } as Produit;
    mockProduits.push(newProduit);
    return newProduit;
  }
  const response = await fetchApi<{ produit: Produit }>('/produits', {
    method: 'POST',
    body: JSON.stringify(produit),
  });
  return response.produit;
}

export async function updateProduit(id: number, produit: Partial<Produit>): Promise<Produit> {
  if (MOCK_MODE) {
    const index = mockProduits.findIndex(p => p.id === id);
    if (index !== -1) {
      mockProduits[index] = { ...mockProduits[index], ...produit };
      return mockProduits[index];
    }
    throw new Error('Produit non trouvé');
  }
  const response = await fetchApi<{ produit: Produit }>(`/produits/${id}`, {
    method: 'PUT',
    body: JSON.stringify(produit),
  });
  return response.produit;
}

export async function deleteProduit(id: number): Promise<void> {
  if (MOCK_MODE) {
    const index = mockProduits.findIndex(p => p.id === id);
    if (index !== -1) mockProduits.splice(index, 1);
    return;
  }
  await fetchApi(`/produits/${id}`, { method: 'DELETE' });
}

// === BALISES ET REMARQUES RAPIDES ===
export type EntiteBalise =
  | 'client' | 'fournisseur' | 'produit' | 'employe'
  | 'devis' | 'commande_client' | 'facture' | 'bon_livraison' | 'commande_fournisseur';
export type CouleurBalise = 'gris' | 'bleu' | 'vert' | 'jaune' | 'orange' | 'rouge' | 'violet' | 'rose';

export interface BaliseItem {
  id: number;
  balise_id: number;
  libelle: string;
  couleur: CouleurBalise;
  ordre: number;
  actif: boolean;
}

export interface Balise {
  id: number;
  nom: string;
  /** choix : un seul item par fiche ; options : plusieurs. */
  type: 'choix' | 'options';
  entites: EntiteBalise[];
  ordre: number;
  actif: boolean;
  items: BaliseItem[];
}

export interface RemarqueRapide {
  id: number;
  categorie: string | null;
  texte: string;
}

const cleEntreprise = () => getAuthHeaders()['X-Tenant-Slug'] ?? '';

// Balises actives d'un type de fiche, demandées une fois pour toutes les lignes d'une liste.
const cacheBalisesActives = new Map<string, Promise<Balise[]>>();

export function balisesActives(entite: EntiteBalise): Promise<Balise[]> {
  const cle = `${cleEntreprise()}:${entite}`;
  if (!cacheBalisesActives.has(cle)) {
    const requete = fetchApi<Balise[]>(`/balises?entite=${entite}&actifs=1`);
    requete.catch(() => cacheBalisesActives.delete(cle));
    cacheBalisesActives.set(cle, requete);
  }
  return cacheBalisesActives.get(cle)!;
}

// Items posés : les demandes des lignes d'une liste partent ensemble, par lots de 200.
const lotsPoses = new Map<string, { ids: Set<number>; resultat: Promise<Record<string, number[]>> }>();

export function posesBalises(entite: EntiteBalise, entiteId: number): Promise<number[]> {
  const cle = `${cleEntreprise()}:${entite}`;
  let lot = lotsPoses.get(cle);
  if (!lot) {
    const ids = new Set<number>();
    const resultat = new Promise(attendre => setTimeout(attendre, 20)).then(async () => {
      lotsPoses.delete(cle);
      const tous = [...ids];
      const morceaux: Record<string, number[]>[] = [];
      for (let i = 0; i < tous.length; i += 200) {
        morceaux.push(await fetchApi<Record<string, number[]>>(`/balises/affectations?entite=${entite}&ids=${tous.slice(i, i + 200).join(',')}`));
      }
      return Object.assign({}, ...morceaux) as Record<string, number[]>;
    });
    lot = { ids, resultat };
    lotsPoses.set(cle, lot);
  }
  lot.ids.add(entiteId);
  return lot.resultat.then(poses => poses[String(entiteId)] ?? []);
}

export async function enregistrerPosesBalises(entite: EntiteBalise, entiteId: number, items: number[]): Promise<number[]> {
  const reponse = await fetchApi<{ items: number[] }>('/balises/affectations', { method: 'PUT', body: JSON.stringify({ entite, entite_id: entiteId, items }) });
  return reponse.items;
}

export async function getBalises(): Promise<Balise[]> {
  return fetchApi('/balises');
}

async function modifierBalises<T>(chemin: string, options: ApiOptions): Promise<T> {
  const resultat = await fetchApi<T>(chemin, options);
  cacheBalisesActives.clear();
  return resultat;
}

export const creerBalise = (balise: Pick<Balise, 'nom' | 'type' | 'entites' | 'actif'>) =>
  modifierBalises<Balise>('/balises', { method: 'POST', body: JSON.stringify(balise) });
export const modifierBalise = (id: number, balise: Pick<Balise, 'nom' | 'type' | 'entites' | 'actif'>) =>
  modifierBalises<Balise>(`/balises/${id}`, { method: 'PUT', body: JSON.stringify(balise) });
export const supprimerBalise = (id: number) => modifierBalises<void>(`/balises/${id}`, { method: 'DELETE' });
export const ajouterItemBalise = (baliseId: number, item: Pick<BaliseItem, 'libelle' | 'couleur'>) =>
  modifierBalises<BaliseItem>(`/balises/${baliseId}/items`, { method: 'POST', body: JSON.stringify(item) });
export const modifierItemBalise = (id: number, item: Pick<BaliseItem, 'libelle' | 'couleur' | 'actif'>) =>
  modifierBalises<BaliseItem>(`/balises/items/${id}`, { method: 'PUT', body: JSON.stringify(item) });
export const supprimerItemBalise = (id: number) => modifierBalises<void>(`/balises/items/${id}`, { method: 'DELETE' });

export async function rechercherParBalises(entite: EntiteBalise, items: number[], mode: 'un' | 'tous'): Promise<{ entite_id: number; libelle_entite: string | null }[]> {
  const parametres = new URLSearchParams({ entite, mode });
  items.forEach(id => parametres.append('items[]', String(id)));
  return fetchApi(`/balises/recherche?${parametres}`);
}

// Remarques rapides, chargées une fois par entreprise pour tous les champs de notes.
const cacheRemarques = new Map<string, Promise<RemarqueRapide[]>>();

export function remarquesRapides(): Promise<RemarqueRapide[]> {
  const cle = cleEntreprise();
  if (!cacheRemarques.has(cle)) {
    const requete = fetchApi<RemarqueRapide[]>('/remarques-rapides');
    requete.catch(() => cacheRemarques.delete(cle));
    cacheRemarques.set(cle, requete);
  }
  return cacheRemarques.get(cle)!;
}

async function modifierRemarques<T>(chemin: string, options: ApiOptions): Promise<T> {
  const resultat = await fetchApi<T>(chemin, options);
  cacheRemarques.clear();
  return resultat;
}

export const creerRemarqueRapide = (remarque: Omit<RemarqueRapide, 'id'>) =>
  modifierRemarques<RemarqueRapide>('/remarques-rapides', { method: 'POST', body: JSON.stringify(remarque) });
export const modifierRemarqueRapide = (id: number, remarque: Omit<RemarqueRapide, 'id'>) =>
  modifierRemarques<RemarqueRapide>(`/remarques-rapides/${id}`, { method: 'PUT', body: JSON.stringify(remarque) });
export const supprimerRemarqueRapide = (id: number) => modifierRemarques<void>(`/remarques-rapides/${id}`, { method: 'DELETE' });

// === SECTEURS ===
export async function getSecteurs(): Promise<Secteur[]> {
  if (MOCK_MODE) return mockSecteurs;
  return fetchApi<Secteur[]>('/secteurs');
}

export async function createSecteur(secteur: Omit<Secteur, 'id'>): Promise<Secteur> {
  if (MOCK_MODE) {
    const newSecteur = { ...secteur, id: mockSecteurs.length + 1 };
    mockSecteurs.push(newSecteur);
    return newSecteur;
  }
  return fetchApi<Secteur>('/secteurs', {
    method: 'POST',
    body: JSON.stringify(secteur),
  });
}

export async function updateSecteur(id: number, secteur: Partial<Secteur>): Promise<Secteur> {
  if (MOCK_MODE) {
    const index = mockSecteurs.findIndex(s => s.id === id);
    if (index !== -1) {
      mockSecteurs[index] = { ...mockSecteurs[index], ...secteur };
      return mockSecteurs[index];
    }
    throw new Error('Secteur non trouvé');
  }
  return fetchApi<Secteur>(`/secteurs/${id}`, {
    method: 'PUT',
    body: JSON.stringify(secteur),
  });
}

export async function deleteSecteur(id: number): Promise<void> {
  if (MOCK_MODE) {
    const index = mockSecteurs.findIndex(s => s.id === id);
    if (index !== -1) mockSecteurs.splice(index, 1);
    return;
  }
  await fetchApi(`/secteurs/${id}`, { method: 'DELETE' });
}

// === SCANS ===
export async function getScans(filters?: { employe?: string; secteur?: string }): Promise<InventaireScan[]> {
  if (MOCK_MODE) {
    let result = [...mockScans];
    if (filters?.employe) result = result.filter(s => s.employe === filters.employe);
    if (filters?.secteur) result = result.filter(s => s.secteur === filters.secteur);
    return result;
  }
  const params = new URLSearchParams();
  if (filters?.employe) params.append('employe', filters.employe);
  if (filters?.secteur) params.append('secteur', filters.secteur);
  return fetchApi<InventaireScan[]>(`/scan/historique?${params}`);
}

export async function createScan(scan: Omit<InventaireScan, 'id' | 'date_saisie'>): Promise<InventaireScan> {
  if (MOCK_MODE) {
    const newScan = { ...scan, id: mockScans.length + 1, date_saisie: new Date().toISOString() } as InventaireScan;
    mockScans.push(newScan);
    return newScan;
  }
  const response = await fetchApi<{ scan: InventaireScan }>('/scan/enregistrer', {
    method: 'POST',
    body: JSON.stringify(scan),
  });
  return response.scan;
}

export async function updateScan(id: number, quantite: number): Promise<InventaireScan> {
  if (MOCK_MODE) {
    const scan = mockScans.find(s => s.id === id);
    if (scan) {
      scan.quantite = quantite;
      return scan;
    }
    throw new Error('Scan non trouvé');
  }
  const response = await fetchApi<{ scan: InventaireScan }>(`/scan/${id}`, {
    method: 'PUT',
    body: JSON.stringify({ quantite }),
  });
  return response.scan;
}

export async function deleteScan(id: number): Promise<void> {
  if (MOCK_MODE) {
    const index = mockScans.findIndex(s => s.id === id);
    if (index !== -1) mockScans.splice(index, 1);
    return;
  }
  await fetchApi(`/scan/${id}`, { method: 'DELETE' });
}

// === UTILISATEURS ADMIN ===
export async function getUsers(): Promise<AdminUser[]> {
  return fetchApi<AdminUser[]>('/users');
}

export async function createUser(user: Omit<AdminUser, 'id' | 'derniere_connexion' | 'created_at'> & { password: string }): Promise<AdminUser> {
  const response = await fetchApi<{ user: AdminUser }>('/users', {
    method: 'POST',
    body: JSON.stringify(user),
  });
  return response.user;
}

export async function updateUser(id: number, data: Partial<AdminUser> & { password?: string }): Promise<AdminUser> {
  const response = await fetchApi<{ user: AdminUser }>(`/users/${id}`, {
    method: 'PUT',
    body: JSON.stringify(data),
  });
  return response.user;
}

export async function deleteUser(id: number): Promise<void> {
  await fetchApi(`/users/${id}`, { method: 'DELETE' });
}

// === CHAMPS PERSONNALISÉS ===
export type EntiteChampPersonnalise =
  | 'client' | 'fournisseur' | 'produit' | 'employe'
  | 'devis' | 'commande_client' | 'facture' | 'bon_livraison' | 'commande_fournisseur';
export type TypeChampPersonnalise = 'texte' | 'texte_long' | 'nombre' | 'date' | 'case' | 'liste' | 'liste_multiple' | 'courriel' | 'url' | 'telephone';
export type ValeurChampPersonnalise = string | number | boolean | string[] | null;

export interface DefinitionChampPersonnalise {
  libelle: string;
  options: string[] | null;
  format: 'nombre' | 'montant' | 'pourcentage' | null;
  decimales: number | null;
  obligatoire: boolean;
  sans_doublon: boolean;
  valeur_defaut: ValeurChampPersonnalise;
  aide: string | null;
  actif: boolean;
}

export interface ChampPersonnalise extends DefinitionChampPersonnalise {
  id: number;
  entite: EntiteChampPersonnalise;
  type: TypeChampPersonnalise;
  code: string;
  ordre: number;
}

/** Champ d'une fiche : sa valeur (ou la valeur par défaut tant que rien n'est saisi). */
export interface ChampPersonnaliseFiche extends ChampPersonnalise {
  valeur: ValeurChampPersonnalise;
  saisie: boolean;
}

// Champs actifs par entreprise et type de fiche, demandés une fois pour toutes les lignes d'une liste.
const cacheChampsActifs = new Map<string, Promise<ChampPersonnalise[]>>();

export function champsPersonnalisesActifs(entite: EntiteChampPersonnalise): Promise<ChampPersonnalise[]> {
  const cle = `${getAuthHeaders()['X-Tenant-Slug'] ?? ''}:${entite}`;
  if (!cacheChampsActifs.has(cle)) {
    const requete = fetchApi<ChampPersonnalise[]>(`/champs-personnalises?entite=${entite}&actifs=1`);
    requete.catch(() => cacheChampsActifs.delete(cle));
    cacheChampsActifs.set(cle, requete);
  }
  return cacheChampsActifs.get(cle)!;
}

export async function getChampsPersonnalises(entite: EntiteChampPersonnalise): Promise<ChampPersonnalise[]> {
  return fetchApi(`/champs-personnalises?entite=${entite}`);
}

export async function creerChampPersonnalise(champ: DefinitionChampPersonnalise & { entite: EntiteChampPersonnalise; type: TypeChampPersonnalise }): Promise<ChampPersonnalise> {
  const cree = await fetchApi<ChampPersonnalise>('/champs-personnalises', { method: 'POST', body: JSON.stringify(champ) });
  cacheChampsActifs.clear();
  return cree;
}

export async function modifierChampPersonnalise(id: number, champ: DefinitionChampPersonnalise): Promise<ChampPersonnalise> {
  const modifie = await fetchApi<ChampPersonnalise>(`/champs-personnalises/${id}`, { method: 'PUT', body: JSON.stringify(champ) });
  cacheChampsActifs.clear();
  return modifie;
}

export async function supprimerChampPersonnalise(id: number): Promise<void> {
  await fetchApi(`/champs-personnalises/${id}`, { method: 'DELETE' });
  cacheChampsActifs.clear();
}

export async function ordonnerChampsPersonnalises(entite: EntiteChampPersonnalise, ids: number[]): Promise<ChampPersonnalise[]> {
  const champs = await fetchApi<ChampPersonnalise[]>('/champs-personnalises/ordre', { method: 'PUT', body: JSON.stringify({ entite, ids }) });
  cacheChampsActifs.clear();
  return champs;
}

export async function getValeursChampsPersonnalises(entite: EntiteChampPersonnalise, entiteId: number): Promise<ChampPersonnaliseFiche[]> {
  return fetchApi(`/champs-personnalises/valeurs?entite=${entite}&entite_id=${entiteId}`);
}

export async function enregistrerValeursChampsPersonnalises(
  entite: EntiteChampPersonnalise,
  entiteId: number,
  valeurs: Record<number, ValeurChampPersonnalise>,
): Promise<ChampPersonnaliseFiche[]> {
  return fetchApi('/champs-personnalises/valeurs', { method: 'PUT', body: JSON.stringify({ entite, entite_id: entiteId, valeurs }) });
}

export async function rechercherChampPersonnalise(champId: number, valeur: string): Promise<{ entite_id: number; libelle_entite: string | null; valeur: ValeurChampPersonnalise }[]> {
  return fetchApi(`/champs-personnalises/recherche?${new URLSearchParams({ champ_id: String(champId), valeur })}`);
}

// === FOURNISSEURS ===
export interface Fournisseur {
  id: number;
  code: string;
  raison_sociale: string;
  adresse?: string;
  telephone?: string;
  email?: string;
  contact_nom?: string;
  contact_telephone?: string;
  conditions_paiement?: string;
  devise?: string;
  actif: boolean;
  created_at?: string;
  updated_at?: string;
}

export async function getFournisseurs(params?: { search?: string; actif?: boolean }): Promise<{ data: Fournisseur[]; total: number }> {
  const searchParams = new URLSearchParams();
  if (params?.search) searchParams.append('search', params.search);
  if (params?.actif !== undefined) searchParams.append('actif', params.actif.toString());
  return fetchApi(`/fournisseurs?${searchParams}`);
}

export async function getFournisseursActifs(): Promise<Fournisseur[]> {
  return fetchApi('/fournisseurs/actifs');
}

export async function getFournisseur(id: number): Promise<Fournisseur> {
  return fetchApi(`/fournisseurs/${id}`);
}

export async function createFournisseur(data: Omit<Fournisseur, 'id' | 'created_at' | 'updated_at'>): Promise<Fournisseur> {
  return fetchApi('/fournisseurs', {
    method: 'POST',
    body: JSON.stringify(data),
  });
}

export async function updateFournisseur(id: number, data: Partial<Fournisseur>): Promise<Fournisseur> {
  return fetchApi(`/fournisseurs/${id}`, {
    method: 'PUT',
    body: JSON.stringify(data),
  });
}

export async function deleteFournisseur(id: number): Promise<void> {
  await fetchApi(`/fournisseurs/${id}`, { method: 'DELETE' });
}

// === COMMANDES FOURNISSEUR ===
export interface ComFourLigne {
  id?: number;
  com_four_entete_id?: number;
  produit_id: number;
  produit?: Produit;
  quantite_commandee: number;
  quantite_recue: number;
  unite_achat?: string | null;
  qte_par_unite_achat?: number;
  prix_unitaire: number;
  montant_ligne: number;
}

export interface ComFourEntete {
  id: number;
  numero: string;
  fournisseur_id: number;
  fournisseur?: Fournisseur;
  date_commande: string;
  date_livraison_prevue?: string;
  statut: 'brouillon' | 'envoyee' | 'partielle' | 'complete' | 'annulee';
  montant_total: number;
  devise?: string;
  taux_change?: number;
  notes?: string;
  created_by: number;
  lignes?: ComFourLigne[];
  created_at?: string;
  updated_at?: string;
}

export async function getCommandesFournisseur(params?: { 
  statut?: string; 
  fournisseur_id?: number;
  search?: string;
}): Promise<{ data: ComFourEntete[]; total: number }> {
  const searchParams = new URLSearchParams();
  if (params?.statut) searchParams.append('statut', params.statut);
  if (params?.fournisseur_id) searchParams.append('fournisseur_id', params.fournisseur_id.toString());
  if (params?.search) searchParams.append('search', params.search);
  return fetchApi(`/commandes-fournisseur?${searchParams}`);
}

export async function getCommandeFournisseur(id: number): Promise<ComFourEntete> {
  return fetchApi(`/commandes-fournisseur/${id}`);
}

export async function createCommandeFournisseur(data: {
  fournisseur_id: number;
  date_commande: string;
  date_livraison_prevue?: string;
  notes?: string;
  devise?: string;
  taux_change?: number;
  lignes: { produit_id: number; quantite_commandee: number; prix_unitaire: number }[];
}): Promise<ComFourEntete> {
  return fetchApi('/commandes-fournisseur', {
    method: 'POST',
    body: JSON.stringify(data),
  });
}

export async function updateCommandeFournisseur(id: number, data: {
  fournisseur_id: number;
  date_commande: string;
  date_livraison_prevue?: string;
  notes?: string;
  lignes: { id?: number; produit_id: number; quantite_commandee: number; prix_unitaire: number }[];
}): Promise<ComFourEntete> {
  return fetchApi(`/commandes-fournisseur/${id}`, {
    method: 'PUT',
    body: JSON.stringify(data),
  });
}

export async function validerCommandeFournisseur(id: number): Promise<{ message: string; commande: ComFourEntete }> {
  return fetchApi(`/commandes-fournisseur/${id}/valider`, { method: 'POST' });
}

export async function annulerCommandeFournisseur(id: number): Promise<{ message: string }> {
  return fetchApi(`/commandes-fournisseur/${id}/annuler`, { method: 'POST' });
}

export async function cloturerCommandeFournisseur(id: number): Promise<{ message: string; commande: ComFourEntete }> {
  return fetchApi(`/commandes-fournisseur/${id}/cloturer`, { method: 'POST' });
}

export async function deleteCommandeFournisseur(id: number): Promise<void> {
  await fetchApi(`/commandes-fournisseur/${id}`, { method: 'DELETE' });
}

export function getCommandePdfUrl(id: number): string {
  const stored = localStorage.getItem('prise_auth');
  let token = '';
  if (stored) {
    try {
      const data = JSON.parse(stored);
      token = data.token || '';
    } catch {
      // ignore
    }
  }
  const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
  return `${API_BASE_URL}/commandes-fournisseur/${id}/pdf?token=${token}`;
}

// === PIÈCES JOINTES ===
export type EntitePieceJointe =
  | 'client' | 'fournisseur' | 'produit' | 'employe'
  | 'devis' | 'commande_client' | 'facture' | 'bon_livraison' | 'commande_fournisseur';

export interface PieceJointe {
  id: number;
  entite: EntitePieceJointe;
  entite_id: number;
  categorie: string | null;
  description: string | null;
  nom_original: string;
  type_mime: string | null;
  taille: number;
  /** Type sans risque (PDF, image, texte) : s'ouvre dans un onglet ; les autres se téléchargent. */
  affichable: boolean;
  auteur: string | null;
  created_at: string | null;
  /** Nom de la fiche ou numéro du document (liste globale). */
  libelle_entite?: string | null;
}

export interface PagePiecesJointes {
  data: PieceJointe[];
  current_page: number;
  last_page: number;
  total: number;
}

export async function getPiecesJointes(filtres: { entite?: EntitePieceJointe; entite_id?: number; recherche?: string; page?: number } = {}): Promise<PagePiecesJointes> {
  const parametres = new URLSearchParams();
  Object.entries(filtres).forEach(([cle, valeur]) => { if (valeur !== undefined && valeur !== '') parametres.set(cle, String(valeur)); });
  return fetchApi(`/pieces-jointes?${parametres}`);
}

export async function getCategoriesPiecesJointes(entite?: EntitePieceJointe): Promise<string[]> {
  return fetchApi(`/pieces-jointes/categories${entite ? `?entite=${entite}` : ''}`);
}

export async function ajouterPiecesJointes(
  entite: EntitePieceJointe,
  entiteId: number,
  fichiers: { fichier: File; description: string }[],
  categorie: string,
): Promise<PieceJointe[]> {
  const formulaire = new FormData();
  formulaire.append('entite', entite);
  formulaire.append('entite_id', String(entiteId));
  if (categorie) formulaire.append('categorie', categorie);
  fichiers.forEach(({ fichier, description }, index) => {
    formulaire.append(`fichiers[${index}]`, fichier, fichier.name);
    formulaire.append(`descriptions[${index}]`, description);
  });
  // Envoi de fichiers : délai plus long que les appels ordinaires.
  return fetchApi('/pieces-jointes', { method: 'POST', body: formulaire, timeoutMs: 120000 });
}

export async function modifierPieceJointe(id: number, donnees: { description: string | null; categorie: string | null }): Promise<PieceJointe> {
  return fetchApi(`/pieces-jointes/${id}`, { method: 'PUT', body: JSON.stringify(donnees) });
}

export async function supprimerPieceJointe(id: number): Promise<void> {
  await fetchApi(`/pieces-jointes/${id}`, { method: 'DELETE' });
}

/**
 * Ouvre une pièce jointe dans un nouvel onglet si son type est sans risque (PDF, image, texte),
 * sinon la télécharge. Le jeton part dans l'en-tête ; l'onglet est ouvert pendant le clic pour ne
 * pas être bloqué par le navigateur.
 */
export async function ouvrirPieceJointe(piece: PieceJointe): Promise<void> {
  const fenetre = piece.affichable ? window.open('', '_blank') : null;
  try {
    const contenu = await fetchApi<Blob>(`/pieces-jointes/${piece.id}/fichier`, { responseType: 'blob', headers: { Accept: '*/*' }, timeoutMs: 120000 });
    const url = URL.createObjectURL(contenu);
    if (fenetre) {
      fenetre.location.href = url;
    } else {
      const lien = document.createElement('a');
      lien.href = url;
      lien.download = piece.nom_original;
      lien.click();
    }
    setTimeout(() => URL.revokeObjectURL(url), 60000);
  } catch (erreur) {
    fenetre?.close();
    throw erreur;
  }
}

// === RECEPTIONS ===
export interface ReceptionLigne {
  id: number;
  com_four_ligne_id: number;
  date_reception: string;
  quantite_recue: number;
  secteur_id?: number;
  lot_numero?: string;
  date_peremption?: string;
  notes?: string;
  received_by: number;
  ligne_commande?: ComFourLigne;
  secteur?: Secteur;
}

export async function getReceptions(params?: { 
  commande_id?: number;
  date_debut?: string;
  date_fin?: string;
}): Promise<{ data: ReceptionLigne[]; total: number }> {
  const searchParams = new URLSearchParams();
  if (params?.commande_id) searchParams.append('commande_id', params.commande_id.toString());
  if (params?.date_debut) searchParams.append('date_debut', params.date_debut);
  if (params?.date_fin) searchParams.append('date_fin', params.date_fin);
  return fetchApi(`/receptions?${searchParams}`);
}

export async function getCommandesEnAttente(): Promise<ComFourEntete[]> {
  return fetchApi('/receptions/commandes-en-attente');
}

export async function getLignesEnAttente(commandeId: number): Promise<ComFourLigne[]> {
  return fetchApi(`/receptions/commande/${commandeId}/lignes`);
}

export async function createReception(data: {
  com_four_ligne_id: number;
  date_reception: string;
  quantite_recue: number;
  secteur_id?: number;
  lot_numero?: string;
  date_peremption?: string;
  notes?: string;
}): Promise<{ message: string; reception: ReceptionLigne }> {
  return fetchApi('/receptions', {
    method: 'POST',
    body: JSON.stringify(data),
  });
}

export async function createReceptionMultiple(data: {
  commande_id: number;
  date_reception: string;
  receptions: {
    com_four_ligne_id: number;
    quantite_recue: number;
    secteur_id?: number;
    lot_numero?: string;
    date_peremption?: string;
    notes?: string;
  }[];
}): Promise<{ message: string; receptions: ReceptionLigne[] }> {
  return fetchApi('/receptions/multiple', {
    method: 'POST',
    body: JSON.stringify(data),
  });
}

// ============================================
// GESTION CLIENTS & VENTES
// ============================================

// Types
export interface Client {
  id: number;
  code: string;
  raison_sociale: string;
  adresse_facturation: string;
  adresse_livraison?: string;
  ville: string;
  code_postal: string;
  telephone?: string;
  email?: string;
  contact_nom?: string;
  contact_telephone?: string;
  encours_max?: number;
  encours_actuel: number;
  taux_remise_global: number;
  actif: boolean;
  conditions_paiement?: ConditionPaiement[];
}

export interface ConditionPaiement {
  id: number;
  client_id: number;
  libelle: string;
  nb_jours: number;
  pourcentage: number;
  ordre: number;
}

/** Détail d'un document par taxe (groupes de taxes) ; vide pour le calcul au taux unique. */
export interface RecapitulatifTaxe {
  nom: string;
  taux: number;
  base: number;
  montant: number;
}

export interface ComClientEntete {
  recapitulatif_taxes?: RecapitulatifTaxe[];
  id: number;
  numero: string;
  client_id: number;
  client?: Client;
  date_commande: string;
  date_livraison_souhaitee?: string;
  statut: 'brouillon' | 'en_attente' | 'acceptee' | 'refusee' | 'facturee' | 'annulee';
  remise_globale: number;
  montant_ht: number;
  montant_tva: number;
  montant_ttc: number;
  notes?: string;
  motif_refus?: string;
  lignes?: ComClientLigne[];
}

export interface ComClientLigne {
  id: number;
  com_entete_id: number;
  produit_id: number;
  quantite: number;
  prix_unitaire_ht: number;
  taux_tva: number;
  remise_ligne: number;
  montant_ht: number;
  montant_ttc: number;
}

export interface Facture {
  recapitulatif_taxes?: RecapitulatifTaxe[];
  id: number;
  numero: string;
  commande_id?: number;
  client_id: number;
  client?: Client;
  facture_mere_id?: number;
  date_facture: string;
  date_echeance?: string;
  statut: 'brouillon' | 'emise' | 'partiellement_payee' | 'payee' | 'annulee';
  montant_ht: number;
  montant_tva: number;
  montant_ttc: number;
  montant_paye: number;
  reste_a_payer: number;
  lignes?: FactureLigne[];
  echeances?: FactureEcheance[];
  bon_livraison?: BonLivraison;
}

export interface FactureLigne {
  id: number;
  facture_id: number;
  produit_id: number;
  quantite: number;
  prix_unitaire_ht: number;
  taux_tva: number;
  montant_ht: number;
  montant_tva: number;
  montant_ttc: number;
}

export interface FactureEcheance {
  id: number;
  facture_id: number;
  date_echeance: string;
  montant: number;
  montant_paye: number;
  statut: 'en_attente' | 'partiellement_payee' | 'payee';
  ordre: number;
}

export interface BonLivraison {
  id: number;
  numero: string;
  facture_id: number;
  facture?: Facture;
  mode_livraison: 'entreprise' | 'retrait_client';
  statut: 'cree' | 'en_preparation' | 'pret' | 'en_livraison' | 'livre_complet' | 'livre_partiel' | 'annule';
  date_preparation?: string;
  date_pret?: string;
  date_livraison?: string;
  lignes?: BonLivraisonLigne[];
}

export interface BonLivraisonLigne {
  id: number;
  bon_id: number;
  produit_id: number;
  quantite_a_livrer: number;
  quantite_preparee: number;
  quantite_livree: number;
  statut_ligne: 'a_preparer' | 'en_cours' | 'prepare' | 'charge' | 'livre';
}

export interface Camion {
  id: number;
  immatriculation: string;
  marque?: string;
  modele?: string;
  type: 'camionnette' | 'camion' | 'semi_remorque';
  capacite_kg?: number;
  capacite_m3?: number;
  date_controle_technique?: string;
  actif: boolean;
}

export interface Tournee {
  id: number;
  numero: string;
  date_tournee: string;
  camion_id?: number;
  camion?: Camion;
  livreur_id?: number;
  zone?: string;
  statut: 'planifiee' | 'en_cours' | 'terminee' | 'annulee';
  heure_depart?: string;
  heure_retour?: string;
  tournee_bons?: TourneeBon[];
}

export interface TourneeBon {
  id: number;
  tournee_id: number;
  bon_livraison_id: number;
  bon_livraison?: BonLivraison;
  ordre_livraison: number;
  heure_livraison?: string;
  statut: 'en_attente' | 'livre' | 'echec';
}

export interface ZonePreparation {
  id: number;
  code: string;
  nom: string;
  description?: string;
  actif: boolean;
}

// Clients API
export async function getClients(params?: { search?: string; actif?: boolean }): Promise<{ data: Client[] }> {
  const queryParams = new URLSearchParams();
  if (params?.search) queryParams.append('search', params.search);
  if (params?.actif !== undefined) queryParams.append('actif', String(params.actif));
  return fetchApi(`/clients?${queryParams.toString()}`);
}

export async function getClientsActifs(): Promise<Client[]> {
  return fetchApi('/clients/actifs');
}

export async function getClient(id: number): Promise<Client> {
  return fetchApi(`/clients/${id}`);
}

export async function createClient(data: Partial<Client>): Promise<Client> {
  return fetchApi('/clients', { method: 'POST', body: JSON.stringify(data) });
}

export async function updateClient(id: number, data: Partial<Client>): Promise<Client> {
  return fetchApi(`/clients/${id}`, { method: 'PUT', body: JSON.stringify(data) });
}

export async function deleteClient(id: number): Promise<void> {
  return fetchApi(`/clients/${id}`, { method: 'DELETE' });
}

export async function getConditionsPaiement(clientId: number): Promise<ConditionPaiement[]> {
  return fetchApi(`/clients/${clientId}/conditions-paiement`);
}

export async function setConditionsPaiement(clientId: number, conditions: Partial<ConditionPaiement>[]): Promise<ConditionPaiement[]> {
  return fetchApi(`/clients/${clientId}/conditions-paiement`, {
    method: 'POST',
    body: JSON.stringify({ conditions }),
  });
}

// Commandes Clients API
export async function getCommandesClient(params?: { statut?: string; client_id?: number }): Promise<{ data: ComClientEntete[] }> {
  const queryParams = new URLSearchParams();
  if (params?.statut) queryParams.append('statut', params.statut);
  if (params?.client_id) queryParams.append('client_id', String(params.client_id));
  return fetchApi(`/commandes-client?${queryParams.toString()}`);
}

export async function getCommandeClient(id: number): Promise<ComClientEntete> {
  return fetchApi(`/commandes-client/${id}`);
}

export async function createCommandeClient(data: {
  client_id: number;
  date_commande: string;
  date_livraison_souhaitee?: string;
  remise_globale?: number;
  notes?: string;
  lignes: { produit_id: number; quantite: number; prix_unitaire_ht: number; taux_tva?: number; remise_ligne?: number }[];
}): Promise<ComClientEntete> {
  return fetchApi('/commandes-client', { method: 'POST', body: JSON.stringify(data) });
}

export async function updateCommandeClient(id: number, data: Partial<ComClientEntete>): Promise<ComClientEntete> {
  return fetchApi(`/commandes-client/${id}`, { method: 'PUT', body: JSON.stringify(data) });
}

export async function soumettreCommandeClient(id: number): Promise<ComClientEntete> {
  return fetchApi(`/commandes-client/${id}/soumettre`, { method: 'POST' });
}

export async function accepterCommandeClient(id: number): Promise<ComClientEntete> {
  return fetchApi(`/commandes-client/${id}/accepter`, { method: 'POST' });
}

export async function refuserCommandeClient(id: number, motif_refus: string): Promise<ComClientEntete> {
  return fetchApi(`/commandes-client/${id}/refuser`, { method: 'POST', body: JSON.stringify({ motif_refus }) });
}

export async function deleteCommandeClient(id: number): Promise<void> {
  return fetchApi(`/commandes-client/${id}`, { method: 'DELETE' });
}

// Factures API
export async function getFactures(params?: { statut?: string; client_id?: number }): Promise<{ data: Facture[] }> {
  const queryParams = new URLSearchParams();
  if (params?.statut) queryParams.append('statut', params.statut);
  if (params?.client_id) queryParams.append('client_id', String(params.client_id));
  return fetchApi(`/factures?${queryParams.toString()}`);
}

export async function getFacture(id: number): Promise<Facture> {
  return fetchApi(`/factures/${id}`);
}

export async function creerFactureDepuisCommande(commandeId: number): Promise<Facture> {
  return fetchApi(`/factures/commande/${commandeId}`, { method: 'POST' });
}

export async function emettreFacture(id: number): Promise<Facture> {
  return fetchApi(`/factures/${id}/emettre`, { method: 'POST' });
}

export async function enregistrerPaiement(id: number, data: {
  montant: number;
  date_paiement: string;
  mode_paiement: string;
  reference?: string;
}): Promise<Facture> {
  return fetchApi(`/factures/${id}/paiement`, { method: 'POST', body: JSON.stringify(data) });
}

export async function creerBonLivraison(factureId: number): Promise<BonLivraison> {
  return fetchApi(`/factures/${factureId}/creer-bl`, { method: 'POST' });
}

export async function createFacture(data: {
  client_id: number;
  date_facture: string;
  date_echeance?: string;
  notes?: string;
  lignes: { produit_id: number; quantite: number; prix_unitaire_ht: number; taux_tva?: number; remise_ligne?: number }[];
}): Promise<Facture> {
  return fetchApi('/factures', { method: 'POST', body: JSON.stringify(data) });
}

export async function deleteFacture(id: number): Promise<void> {
  return fetchApi(`/factures/${id}`, { method: 'DELETE' });
}

// Bons de Livraison API
export async function getBonsLivraison(params?: { statut?: string }): Promise<{ data: BonLivraison[] }> {
  const queryParams = new URLSearchParams();
  if (params?.statut) queryParams.append('statut', params.statut);
  return fetchApi(`/bons-livraison?${queryParams.toString()}`);
}

export async function getBonLivraison(id: number): Promise<BonLivraison> {
  return fetchApi(`/bons-livraison/${id}`);
}

export async function demarrerPreparation(id: number): Promise<BonLivraison> {
  return fetchApi(`/bons-livraison/${id}/preparer`, { method: 'POST' });
}

export async function updateLignesBL(id: number, lignes: { id: number; quantite_preparee: number }[]): Promise<BonLivraison> {
  return fetchApi(`/bons-livraison/${id}/lignes`, { method: 'PUT', body: JSON.stringify({ lignes }) });
}

export async function marquerBLPret(id: number): Promise<BonLivraison> {
  return fetchApi(`/bons-livraison/${id}/pret`, { method: 'POST' });
}

export async function enregistrerLivraison(id: number, data: {
  lignes: { id: number; quantite_livree: number }[];
  signature_client?: string;
  notes_livraison?: string;
}): Promise<BonLivraison> {
  return fetchApi(`/bons-livraison/${id}/livrer`, { method: 'POST', body: JSON.stringify(data) });
}

export async function annulerBL(id: number): Promise<BonLivraison> {
  return fetchApi(`/bons-livraison/${id}/annuler`, { method: 'POST' });
}

// Camions API
export async function getCamions(params?: { actif?: boolean }): Promise<Camion[]> {
  const queryParams = new URLSearchParams();
  if (params?.actif !== undefined) queryParams.append('actif', String(params.actif));
  return fetchApi(`/camions?${queryParams.toString()}`);
}

export async function getCamionsDisponibles(date: string): Promise<Camion[]> {
  return fetchApi(`/camions/disponibles?date=${date}`);
}

export async function getCamion(id: number): Promise<Camion> {
  return fetchApi(`/camions/${id}`);
}

export async function createCamion(data: Partial<Camion>): Promise<Camion> {
  return fetchApi('/camions', { method: 'POST', body: JSON.stringify(data) });
}

export async function updateCamion(id: number, data: Partial<Camion>): Promise<Camion> {
  return fetchApi(`/camions/${id}`, { method: 'PUT', body: JSON.stringify(data) });
}

export async function deleteCamion(id: number): Promise<void> {
  return fetchApi(`/camions/${id}`, { method: 'DELETE' });
}

// Tournées API
export async function getTournees(params?: { statut?: string; date?: string }): Promise<{ data: Tournee[] }> {
  const queryParams = new URLSearchParams();
  if (params?.statut) queryParams.append('statut', params.statut);
  if (params?.date) queryParams.append('date', params.date);
  return fetchApi(`/tournees?${queryParams.toString()}`);
}

export async function getTournee(id: number): Promise<Tournee> {
  return fetchApi(`/tournees/${id}`);
}

export async function createTournee(data: {
  date_tournee: string;
  camion_id: number;
  livreur_id?: number;
  zone?: string;
}): Promise<Tournee> {
  return fetchApi('/tournees', { method: 'POST', body: JSON.stringify(data) });
}

export async function ajouterBonATournee(tourneeId: number, bonLivraisonId: number): Promise<Tournee> {
  return fetchApi(`/tournees/${tourneeId}/ajouter-bon`, {
    method: 'POST',
    body: JSON.stringify({ bon_livraison_id: bonLivraisonId }),
  });
}

export async function retirerBonDeTournee(tourneeId: number, bonLivraisonId: number): Promise<Tournee> {
  return fetchApi(`/tournees/${tourneeId}/bon/${bonLivraisonId}`, { method: 'DELETE' });
}

export async function deleteTournee(id: number): Promise<void> {
  return fetchApi(`/tournees/${id}`, { method: 'DELETE' });
}

export async function demarrerTournee(id: number, km_depart?: number): Promise<Tournee> {
  return fetchApi(`/tournees/${id}/demarrer`, { method: 'POST', body: JSON.stringify({ km_depart }) });
}

export async function terminerTournee(id: number, km_retour?: number): Promise<Tournee> {
  return fetchApi(`/tournees/${id}/terminer`, { method: 'POST', body: JSON.stringify({ km_retour }) });
}

// Zones de Préparation API
export async function getZonesPreparation(): Promise<ZonePreparation[]> {
  return fetchApi('/zones-preparation');
}

export async function createZonePreparation(data: Partial<ZonePreparation>): Promise<ZonePreparation> {
  return fetchApi('/zones-preparation', { method: 'POST', body: JSON.stringify(data) });
}

export async function updateZonePreparation(id: number, data: Partial<ZonePreparation>): Promise<ZonePreparation> {
  return fetchApi(`/zones-preparation/${id}`, { method: 'PUT', body: JSON.stringify(data) });
}

export async function deleteZonePreparation(id: number): Promise<void> {
  return fetchApi(`/zones-preparation/${id}`, { method: 'DELETE' });
}

// Localisations API
export async function getProduitLocalisation(produitId: number): Promise<{
  produit_id: number;
  localisations: unknown[];
  resume: { disponible: number; reserve: number; en_preparation: number; en_transit: number; total: number };
}> {
  return fetchApi(`/produits/${produitId}/localisation`);
}

export async function getMouvementsProduit(produitId: number): Promise<unknown[]> {
  return fetchApi(`/produits/${produitId}/mouvements`);
}

// === CONFIGURATIONS ===
export interface ConfigurationFormat {
  id?: number;
  entite: string;
  prefixe: string;
  suffixe: string;
  longueur: number;
  separateur: string;
  auto_increment: boolean;
  prochain_numero: number;
}

export async function getConfigurations(): Promise<ConfigurationFormat[]> {
  return fetchApi('/configurations');
}

export async function getConfiguration(entite: string): Promise<ConfigurationFormat> {
  return fetchApi(`/configurations/${entite}`);
}

export async function updateConfiguration(entite: string, data: Partial<ConfigurationFormat>): Promise<{ success: boolean; configuration: ConfigurationFormat }> {
  return fetchApi(`/configurations/${entite}`, { method: 'PUT', body: JSON.stringify(data) });
}

export interface TenantParametres {
  nom_entreprise?: string;
  adresse?: string;
  telephone?: string;
  email?: string;
  siret?: string;
  tva_numero?: string;
  logo_url?: string;
  devise_symbole?: string;
  devise_code?: string;
  tva_taux?: number;
  delai_paiement_jours?: number;
  delai_livraison_jours?: number;
  stock_alerte_email?: string;
  stock_seuil_defaut?: number;
}

export interface TenantTaxe {
  id?: number;
  nom: string;
  taux: number;
  par_defaut: boolean;
}

export async function getTaxes(): Promise<TenantTaxe[]> {
  return fetchApi('/taxes');
}

export async function createTaxe(data: Omit<TenantTaxe, 'id'>): Promise<TenantTaxe> {
  return fetchApi('/taxes', { method: 'POST', body: JSON.stringify(data) });
}

export async function updateTaxe(id: number, data: Omit<TenantTaxe, 'id'>): Promise<TenantTaxe> {
  return fetchApi(`/taxes/${id}`, { method: 'PUT', body: JSON.stringify(data) });
}

export async function deleteTaxe(id: number): Promise<void> {
  await fetchApi(`/taxes/${id}`, { method: 'DELETE' });
}

// ── Référentiels génériques (unités, catégories, devises…) ──────────────────
export type TypeReferentiel =
  | 'unites'
  | 'unite_conversions'
  | 'categories'
  | 'fabricants'
  | 'modes_paiement'
  | 'conditions_paiement'
  | 'comptes_bancaires'
  | 'devises'
  | 'entrepots'
  | 'territoires'
  | 'itineraires'
  | 'representants'
  | 'transporteurs'
  | 'raisons'
  | 'statuts_personnalises';

export interface FicheReferentiel {
  id: number;
  [champ: string]: unknown;
}

export interface TauxDevise {
  id: number;
  taux: string;
  date_effet: string;
}

export async function getReferentiel(type: TypeReferentiel, actifsSeulement = false): Promise<FicheReferentiel[]> {
  return fetchApi(`/referentiels/${type}${actifsSeulement ? '?actif=1' : ''}`);
}

export async function createReferentiel(type: TypeReferentiel, data: Record<string, unknown>): Promise<FicheReferentiel> {
  return fetchApi(`/referentiels/${type}`, { method: 'POST', body: JSON.stringify(data) });
}

export async function updateReferentiel(type: TypeReferentiel, id: number, data: Record<string, unknown>): Promise<FicheReferentiel> {
  return fetchApi(`/referentiels/${type}/${id}`, { method: 'PUT', body: JSON.stringify(data) });
}

export async function deleteReferentiel(type: TypeReferentiel, id: number): Promise<void> {
  await fetchApi(`/referentiels/${type}/${id}`, { method: 'DELETE' });
}

export async function getHistoriqueDevise(id: number): Promise<TauxDevise[]> {
  return fetchApi(`/referentiels/devises/${id}/historique`);
}

// ── Groupes de taxes (TPS + TVQ, TVA + CAC…) ────────────────────────────────
export type AppliqueSur = 'base' | 'base_et_taxes_precedentes' | 'taxe_precedente';

export interface LigneGroupeTaxe {
  tenant_taxe_id: number;
  applique_sur: AppliqueSur;
  taxe?: { id: number; nom: string; taux: string };
}

export interface GroupeTaxe {
  id: number;
  code: string;
  nom: string;
  par_defaut: boolean;
  actif: boolean;
  taux_effectif: number;
  lignes: LigneGroupeTaxe[];
}

export interface DetailTaxes {
  montant_ht: number;
  taxes: { taxe_id: number; nom: string; taux: number; applique_sur: AppliqueSur; base: number; montant: number }[];
  total_taxes: number;
  montant_ttc: number;
  taux_effectif: number;
}

export interface DonneesGroupeTaxe {
  code: string;
  nom: string;
  par_defaut: boolean;
  actif: boolean;
  lignes: { tenant_taxe_id: number; applique_sur: AppliqueSur }[];
}

export async function getGroupesTaxes(): Promise<GroupeTaxe[]> {
  return fetchApi('/groupes-taxes');
}

export async function createGroupeTaxe(data: DonneesGroupeTaxe): Promise<GroupeTaxe> {
  return fetchApi('/groupes-taxes', { method: 'POST', body: JSON.stringify(data) });
}

export async function updateGroupeTaxe(id: number, data: DonneesGroupeTaxe): Promise<GroupeTaxe> {
  return fetchApi(`/groupes-taxes/${id}`, { method: 'PUT', body: JSON.stringify(data) });
}

export async function deleteGroupeTaxe(id: number): Promise<void> {
  await fetchApi(`/groupes-taxes/${id}`, { method: 'DELETE' });
}

/**
 * Ouvre le PDF d'un document (ex. `/factures/12/pdf`) dans un nouvel onglet. Le jeton part dans
 * l'en-tête, pas dans l'URL. L'onglet est ouvert pendant le clic (sinon le navigateur bloque la
 * fenêtre) ; s'il est bloqué malgré tout, le PDF est téléchargé.
 */
export async function ouvrirPdf(chemin: string, nomFichier: string): Promise<void> {
  const fenetre = window.open('', '_blank');
  try {
    const entetes = getAuthHeaders();
    delete entetes['Content-Type'];
    const reponse = await fetch(`${API_BASE_URL}${chemin}`, { headers: { ...entetes, Accept: 'application/pdf' } });
    if (!reponse.ok) throw new Error(`HTTP ${reponse.status}`);
    const url = URL.createObjectURL(await reponse.blob());
    if (fenetre) {
      fenetre.location.href = url;
    } else {
      const lien = document.createElement('a');
      lien.href = url;
      lien.download = nomFichier;
      lien.click();
    }
    setTimeout(() => URL.revokeObjectURL(url), 60000);
  } catch (erreur) {
    fenetre?.close();
    throw erreur;
  }
}

export async function calculerGroupeTaxe(id: number, montantHt: number): Promise<DetailTaxes> {
  return fetchApi(`/groupes-taxes/${id}/calculer`, { method: 'POST', body: JSON.stringify({ montant_ht: montantHt }) });
}

// === COURRIELS (paramètres d'envoi et modèles) ===
export type TypeCourriel = 'devis' | 'commande_client' | 'facture' | 'bon_livraison' | 'commande_fournisseur' | 'general';
export type LangueCourriel = 'fr' | 'en';

export interface ContenuModeleCourriel {
  objet: string;
  contenu: string;
  nom_fichier: string | null;
  repondre_a: string | null;
  accuse_lecture: boolean;
}

export interface ModeleCourriel extends ContenuModeleCourriel {
  id: number;
  type: TypeCourriel;
  langue: LangueCourriel;
}

export interface CatalogueCourriels {
  langues: LangueCourriel[];
  types: { type: TypeCourriel; variables: string[]; piece_jointe: boolean; defauts: Record<LangueCourriel, ContenuModeleCourriel> }[];
}

export interface ApercuCourriel {
  objet: string;
  html: string;
  texte: string;
  nom_fichier: string | null;
  inconnues: string[];
  /** Numéro du document utilisé, ou null pour des valeurs d'exemple. */
  document: string | null;
}

export interface ParametresCourriel {
  transport: 'plateforme' | 'smtp';
  smtp_hote: string | null;
  smtp_port: number | null;
  smtp_chiffrement: 'tls' | 'ssl' | 'aucun' | null;
  smtp_utilisateur: string | null;
  /** Envoyé seulement pour le changer ; jamais renvoyé par l'API. */
  smtp_mot_de_passe?: string;
  smtp_mot_de_passe_defini?: boolean;
  expediteur_adresse: string | null;
  expediteur_nom: string | null;
  repondre_a: string | null;
  copie_cachee: string | null;
  signature: string | null;
  adresse_plateforme?: string | null;
}

export async function getCatalogueCourriels(): Promise<CatalogueCourriels> {
  return fetchApi('/courriels/catalogue');
}

export async function getModelesCourriel(): Promise<ModeleCourriel[]> {
  return fetchApi('/courriels/modeles');
}

export async function createModeleCourriel(modele: Omit<ModeleCourriel, 'id'>): Promise<ModeleCourriel> {
  return fetchApi('/courriels/modeles', { method: 'POST', body: JSON.stringify(modele) });
}

export async function updateModeleCourriel(id: number, modele: ContenuModeleCourriel): Promise<ModeleCourriel> {
  return fetchApi(`/courriels/modeles/${id}`, { method: 'PUT', body: JSON.stringify(modele) });
}

export async function deleteModeleCourriel(id: number): Promise<void> {
  await fetchApi(`/courriels/modeles/${id}`, { method: 'DELETE' });
}

export async function apercuModeleCourriel(modele: { type: TypeCourriel; langue: LangueCourriel; objet: string; contenu: string; nom_fichier: string | null }): Promise<ApercuCourriel> {
  return fetchApi('/courriels/modeles/apercu', { method: 'POST', body: JSON.stringify(modele) });
}

export async function getParametresCourriel(): Promise<ParametresCourriel> {
  return fetchApi('/courriels/parametres');
}

export async function updateParametresCourriel(parametres: ParametresCourriel): Promise<ParametresCourriel> {
  return fetchApi('/courriels/parametres', { method: 'PUT', body: JSON.stringify(parametres) });
}

export async function testerParametresCourriel(destinataire: string, langue: LangueCourriel): Promise<{ message: string }> {
  return fetchApi('/courriels/parametres/test', { method: 'POST', body: JSON.stringify({ destinataire, langue }) });
}

// === COURRIELS (envoi des documents, file d'envoi et historique) ===
export type TypeDocumentCourriel = Exclude<TypeCourriel, 'general'>;
export type StatutCourriel = 'en_attente' | 'en_cours' | 'envoye' | 'echec';

export interface PreparationCourriel {
  type: TypeDocumentCourriel;
  document_id: number;
  document_numero: string;
  langue: LangueCourriel;
  modele_personnalise: boolean;
  destinataires: string[];
  objet: string;
  contenu: string;
  piece_jointe: string | null;
}

export interface EnvoiCourriel {
  id: number;
  type: TypeDocumentCourriel;
  document_id: number | null;
  document_numero: string | null;
  destinataires: string[];
  copies: string[] | null;
  copies_cachees: string[] | null;
  expediteur_adresse: string;
  expediteur_nom: string | null;
  repondre_a: string | null;
  objet: string;
  piece_jointe: string | null;
  statut: StatutCourriel;
  tentatives: number;
  erreur: string | null;
  dernier_essai: string | null;
  envoye_le: string | null;
  created_at: string | null;
  auteur: string | null;
  /** Présents dans le détail d'un envoi seulement. */
  corps_html?: string;
  corps_texte?: string;
}

export interface PageEnvoisCourriel {
  data: EnvoiCourriel[];
  current_page: number;
  last_page: number;
  total: number;
}

export interface NouveauCourriel {
  type: TypeDocumentCourriel;
  document_id: number;
  langue: LangueCourriel;
  destinataires: string[];
  copies: string[];
  copies_cachees: string[];
  objet: string;
  contenu: string;
  joindre_pdf: boolean;
}

export async function preparerCourriel(type: TypeDocumentCourriel, documentId: number, langue: LangueCourriel): Promise<PreparationCourriel> {
  return fetchApi(`/courriels/preparer?${new URLSearchParams({ type, document_id: String(documentId), langue })}`);
}

export async function envoyerCourriel(courriel: NouveauCourriel): Promise<EnvoiCourriel> {
  return fetchApi('/courriels/envois', { method: 'POST', body: JSON.stringify(courriel) });
}

export async function getEnvoisCourriel(filtres: { type?: TypeDocumentCourriel; document_id?: number; statut?: StatutCourriel; page?: number } = {}): Promise<PageEnvoisCourriel> {
  const parametres = new URLSearchParams();
  Object.entries(filtres).forEach(([cle, valeur]) => { if (valeur !== undefined) parametres.set(cle, String(valeur)); });
  return fetchApi(`/courriels/envois?${parametres}`);
}

export async function getEnvoiCourriel(id: number): Promise<EnvoiCourriel> {
  return fetchApi(`/courriels/envois/${id}`);
}

export async function reessayerEnvoiCourriel(id: number): Promise<EnvoiCourriel> {
  return fetchApi(`/courriels/envois/${id}/reessayer`, { method: 'POST' });
}

export async function getParametres(): Promise<TenantParametres> {
  return fetchApi('/parametres');
}

export async function updateParametres(data: TenantParametres): Promise<{ message: string; parametres: TenantParametres }> {
  return fetchApi('/parametres', { method: 'PUT', body: JSON.stringify(data) });
}

export async function getTauxChange(from: string, to: string): Promise<{ from: string; to: string; taux: number; date: string }> {
  return fetchApi(`/taux-change?from=${from}&to=${to}`);
}

export async function genererNumero(entite: string): Promise<{ numero: string; prochain: number }> {
  return fetchApi(`/configurations/${entite}/generer`);
}

// ============================================
// Devis
// ============================================
export interface DevisLigne {
  id?: number;
  produit_id: number;
  produit?: { id: number; numero: string; description: string };
  quantite: number;
  prix_unitaire: number;
  montant_ligne: number;
}

export interface DevisRecord {
  id: number;
  numero: string;
  client_id: number;
  client?: Client;
  date_devis: string;
  date_validite: string;
  statut: 'brouillon' | 'envoye' | 'accepte' | 'refuse' | 'expire';
  montant_total: number;
  notes?: string;
  lignes: DevisLigne[];
}

export async function getDevis(params?: { statut?: string; search?: string }): Promise<{ data: DevisRecord[] }> {
  const qs = new URLSearchParams();
  if (params?.statut) qs.append('statut', params.statut);
  if (params?.search) qs.append('search', params.search);
  return fetchApi(`/devis?${qs.toString()}`);
}

export async function createDevis(data: {
  client_id: number;
  date_devis: string;
  date_validite: string;
  notes?: string;
  lignes: { produit_id: number; quantite: number; prix_unitaire: number }[];
}): Promise<DevisRecord> {
  return fetchApi('/devis', { method: 'POST', body: JSON.stringify(data) });
}

export async function updateDevis(id: number, data: {
  client_id: number;
  date_devis: string;
  date_validite: string;
  notes?: string;
  lignes: { id?: number; produit_id: number; quantite: number; prix_unitaire: number }[];
}): Promise<DevisRecord> {
  return fetchApi(`/devis/${id}`, { method: 'PUT', body: JSON.stringify(data) });
}

export async function envoyerDevis(id: number): Promise<{ message: string; devis: DevisRecord }> {
  return fetchApi(`/devis/${id}/envoyer`, { method: 'POST' });
}

export async function accepterDevis(id: number): Promise<{ message: string; devis: DevisRecord }> {
  return fetchApi(`/devis/${id}/accepter`, { method: 'POST' });
}

export async function refuserDevis(id: number): Promise<{ message: string; devis: DevisRecord }> {
  return fetchApi(`/devis/${id}/refuser`, { method: 'POST' });
}

export async function convertirDevisEnCommande(id: number): Promise<{ message: string; commande: ComClientEntete }> {
  return fetchApi(`/devis/${id}/convertir`, { method: 'POST' });
}

export async function deleteDevis(id: number): Promise<{ message: string }> {
  return fetchApi(`/devis/${id}`, { method: 'DELETE' });
}

export async function consommerNumero(entite: string): Promise<{ numero: string }> {
  return fetchApi(`/configurations/${entite}/consommer`, { method: 'POST' });
}
