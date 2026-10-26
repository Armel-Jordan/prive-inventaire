import type { Employe, Produit, Secteur, InventaireScan, AdminUser } from '@/types';
import { apiFetch, getAuthHeaders, API_BASE_URL, type ApiOptions } from './http';
import { URL_ESPACE_CLIENT } from './portailClients';
import type { ResumeChargement } from './chargement';

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

/** Page de résultats renvoyée par l'API (pagination Laravel). */
export interface PageResultats<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}

/**
 * Tous les éléments d'une liste paginée (totaux, journaux, exports), page après page, dans la limite de
 * `maxPages` pages pour ne pas emballer le navigateur sur un très gros volume.
 */
export async function toutesLesPages<T>(charger: (page: number) => Promise<PageResultats<T>>, maxPages = 50): Promise<T[]> {
  const premiere = await charger(1);
  const elements = [...(premiere.data || [])];
  const derniere = Math.min(premiere.last_page ?? 1, maxPages);
  for (let page = 2; page <= derniere; page++) {
    elements.push(...((await charger(page)).data || []));
  }
  return elements;
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
/** Produits actifs, ou désactivés / tous (écran Produits, pour les réactiver). */
export async function getProduits(statut: 'actifs' | 'inactifs' | 'tous' = 'actifs'): Promise<Produit[]> {
  if (MOCK_MODE) return mockProduits;
  return fetchApi<Produit[]>(statut === 'actifs' ? '/produits' : `/produits?statut=${statut}`);
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
/** Secteurs actifs, ou désactivés / tous (écran Secteurs, pour les réactiver). */
export async function getSecteurs(statut: 'actifs' | 'inactifs' | 'tous' = 'actifs'): Promise<Secteur[]> {
  if (MOCK_MODE) return mockSecteurs;
  return fetchApi<Secteur[]>(statut === 'actifs' ? '/secteurs' : `/secteurs?statut=${statut}`);
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

// === TÂCHES PLANIFIÉES ===
export type RecurrenceTache = 'horaire' | 'quotidienne' | 'hebdomadaire' | 'mensuelle' | 'une_fois';

export interface ReglageTache {
  actif: boolean;
  recurrence: RecurrenceTache;
  /** HH:MM dans le fuseau des tâches (pour « horaire », seules les minutes comptent). */
  heure: string;
  /** 1 = lundi … 7 = dimanche */
  jours_semaine: number[] | null;
  jour_du_mois: number | null;
  /** Exécution unique : AAAA-MM-JJTHH:MM dans le fuseau des tâches. */
  prochaine_execution?: string | null;
  /** Paramètres prévus par la tâche (« jours » : délai d'une alerte ; « emettre » : factures émises) ; les autres sont ignorés. */
  parametres?: ParametresTache;
}

export interface ParametresTache { jours?: number; emettre?: boolean }

export interface TachePlanifiee extends Omit<ReglageTache, 'prochaine_execution'> {
  id: number;
  code: string;
  dernier_resultat: 'succes' | 'erreur' | null;
  statut: 'en_attente' | 'en_cours';
  prochaine_execution: string | null;
  derniere_execution: string | null;
  /** Paramètres réglables, valeurs par défaut comprises (vide si la tâche n'en a pas). */
  parametres: ParametresTache;
  /** Module de l'entreprise requis par la tâche (ventes, achats…), null si aucun. */
  module: string | null;
}

export interface ExecutionTache {
  id: number;
  tache_planifiee_id: number;
  code: string;
  mode: 'planifie' | 'manuel';
  duree_ms: number | null;
  resultat: 'succes' | 'erreur' | null;
  message: string | null;
  debut: string | null;
  fin: string | null;
  auteur: string | null;
}

export interface EtatTachesPlanifiees {
  taches: TachePlanifiee[];
  dernier_passage: { date: string; source: 'planificateur' | 'requetes' } | null;
  declenchement_requetes: boolean;
  fuseau: string;
}

export async function getTachesPlanifiees(): Promise<EtatTachesPlanifiees> {
  return fetchApi('/taches-planifiees');
}

export async function modifierTachePlanifiee(id: number, reglage: ReglageTache): Promise<TachePlanifiee> {
  return fetchApi(`/taches-planifiees/${id}`, { method: 'PUT', body: JSON.stringify(reglage) });
}

export async function executerTachePlanifiee(id: number): Promise<{ execution: ExecutionTache; tache: TachePlanifiee }> {
  // Une tâche peut prendre un moment : délai plus long que les appels ordinaires.
  return fetchApi(`/taches-planifiees/${id}/executer`, { method: 'POST', timeoutMs: 120000 });
}

export async function getHistoriqueTaches(filtres: { tache_id?: number; page?: number } = {}): Promise<{ data: ExecutionTache[]; current_page: number; last_page: number; total: number }> {
  const parametres = new URLSearchParams();
  Object.entries(filtres).forEach(([cle, valeur]) => { if (valeur !== undefined) parametres.set(cle, String(valeur)); });
  return fetchApi(`/taches-planifiees/historique?${parametres}`);
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

/** Page d'un historique paginé par l'API. */
export interface PageHistorique<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}

/** Filtres de l'historique des scans ; la période est en instants (voir lib/periodes). */
export interface FiltresScans {
  employe?: string;
  secteur?: string;
  /** Multi-entrepôts : les scans des secteurs de l'entrepôt. */
  entrepot_id?: number;
  produit?: string;
  depuis?: string;
  jusqu_a?: string;
}

function requeteScans(parametres: object): string {
  const params = new URLSearchParams();
  Object.entries(parametres).forEach(([cle, valeur]) => {
    if (valeur !== undefined && valeur !== '') params.append(cle, String(valeur));
  });
  return params.toString();
}

/** Historique complet des scans, filtré et paginé par l'API (getScans s'arrête aux 100 derniers). */
export async function getHistoriqueScans(filtres: FiltresScans & { page: number; per_page?: number }): Promise<PageHistorique<InventaireScan>> {
  return fetchApi(`/scan/historique?${requeteScans(filtres)}`);
}

/** Fichier CSV de tous les scans filtrés, dates dans le fuseau donné. */
export async function exporterScans(filtres: FiltresScans & { fuseau: string }): Promise<Blob> {
  return fetchApi(`/scan/export?${requeteScans(filtres)}`, { responseType: 'blob' });
}

export interface StatistiquesScans {
  total: number;
  jours_actifs: number;
  moyenne_par_jour: number;
  secteurs_distincts: number;
  employes_distincts: number;
  par_jour: { date: string; total: number }[];
  par_secteur: { nom: string; total: number }[];
  par_employe: { nom: string; total: number }[];
  par_type: { nom: string; total: number }[];
}

/** Statistiques des scans d'une période, calculées par l'API sur tous ses scans. */
export async function getStatistiquesScans(periode: { depuis: string; jusqu_a: string; fuseau: string }): Promise<StatistiquesScans> {
  return fetchApi(`/scan/statistiques?${requeteScans(periode)}`);
}

export interface LigneComparaisonScans {
  numero: string;
  type: string;
  secteur: string;
  quantite_a: number;
  quantite_b: number;
  difference: number;
  pourcentage: number;
}

/** Quantités comptées par produit sur deux périodes (A puis B). */
export async function getComparaisonScans(a: { depuis: string; jusqu_a: string }, b: { depuis: string; jusqu_a: string }): Promise<{ lignes: LigneComparaisonScans[] }> {
  return fetchApi(`/scan/comparaison?${requeteScans({ a_depuis: a.depuis, a_jusqu_a: a.jusqu_a, b_depuis: b.depuis, b_jusqu_a: b.jusqu_a })}`);
}

/** Nombre de scans, scans du jour, instants du premier et du dernier scan. */
export async function getBornesScans(): Promise<{ total: number; today: number; premier: string | null; dernier: string | null }> {
  return fetchApi('/scan/stats');
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

// === CONFIGURATION À COMPLÉTER ===
export interface ReglageAMettreEnPlace {
  cle: string;
  obligatoire: boolean;
  faite: boolean;
  /** Écran où se fait le réglage. */
  lien: string;
  ignoree: { raison: string; par: string | null; le: string | null } | null;
}

export interface ConfigurationACompleter {
  etapes: { cle: string; taches: ReglageAMettreEnPlace[] }[];
  qualite: { cle: string; nombre: number; lien: string }[];
  restantes: { obligatoires: number; recommandees: number };
}

export async function getConfigurationACompleter(): Promise<ConfigurationACompleter> {
  return fetchApi('/configuration-a-completer');
}

export async function ignorerReglage(cle: string, raison: string): Promise<ConfigurationACompleter> {
  return fetchApi(`/configuration-a-completer/${cle}/ignorer`, { method: 'POST', body: JSON.stringify({ raison }) });
}

export async function reprendreReglage(cle: string): Promise<ConfigurationACompleter> {
  return fetchApi(`/configuration-a-completer/${cle}/ignorer`, { method: 'DELETE' });
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
  /** Transporteur qui livre ce fournisseur, repris par ses commandes (phase 1.11b). */
  transporteur_id?: number | null;
  created_at?: string;
  updated_at?: string;
}

export async function getFournisseurs(params?: { search?: string; actif?: boolean; page?: number }): Promise<PageResultats<Fournisseur>> {
  const searchParams = new URLSearchParams();
  if (params?.search) searchParams.append('search', params.search);
  if (params?.actif !== undefined) searchParams.append('actif', params.actif.toString());
  if (params?.page) searchParams.append('page', String(params.page));
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

// Fiche fournisseur : synthèse des achats, historique, produits fournis, contacts, adresses, comptes, remarques
export interface FicheFournisseur extends Fournisseur {
  contacts_count: number;
  adresses_count: number;
  comptes_bancaires_count: number;
  remarques_count: number;
  produits_count: number;
}

export interface SyntheseFournisseur {
  achats: { total: number; annee_courante: number; annee_precedente: number };
  commandes: {
    nombre: number;
    montant_moyen: number;
    brouillons: number;
    premiere: string | null;
    /** Montant dans la devise de la commande. */
    derniere: { id: number; numero: string; date: string; montant: number; devise: string | null; statut: string } | null;
  };
  a_recevoir: { commandes: number; quantite: number; montant: number };
  livraisons: { commandes_livrees: number; delai_moyen_jours: number | null; a_temps: number | null; taux_reception: number | null };
  achats_mensuels: { mois: string; montant: number }[];
  produits_les_plus_achetes: { produit_id: number; numero: string | null; description: string | null; quantite: number; montant: number; commandes: number; dernier_achat: string | null }[];
  /** Retours au fournisseur, dans la devise de l'entreprise : crédits attendus et obtenus (avoirs), retours en cours. */
  retours?: { credits_attendus: number; credits_obtenus: number; en_cours: number; brouillons: number };
}

export interface LigneHistoriqueFournisseur {
  id: number;
  date: string;
  commande_id: number;
  commande_numero: string;
  statut: string;
  produit_id: number;
  produit_numero: string | null;
  produit_description: string | null;
  quantite_commandee: number;
  quantite_recue: number;
  /** Prix et montant dans la devise de la commande. */
  prix_unitaire: number;
  montant: number;
  devise: string | null;
}

export interface HistoriqueFournisseur {
  data: LigneHistoriqueFournisseur[];
  current_page: number;
  last_page: number;
  total: number;
  totaux: { quantite_commandee: number; quantite_recue: number; montant: number };
}

export interface ProduitFourni {
  produit_id: number;
  numero: string;
  description: string;
  reference_fournisseur: string | null;
  prix_achat: number | null;
  delai_livraison: number | null;
  fournisseur_principal: boolean;
  /** Conditionnement chez ce fournisseur : unité d'achat et unités de stock qu'elle contient (vides : ceux du produit). */
  unite_achat?: string | null;
  qte_par_unite_achat?: number | null;
  /** Unité de stock et conditionnement du produit. */
  mesure?: string | null;
  unite_achat_produit?: string | null;
  qte_par_unite_achat_produit?: number;
  dernier_prix: number | null;
  dernier_prix_devise: string | null;
  derniere_commande: string | null;
}

export interface ContactFournisseur {
  id: number;
  nom: string;
  fonction: string | null;
  telephone: string | null;
  telephone_secondaire: string | null;
  email: string | null;
  principal: boolean;
  recoit_commandes: boolean;
  notes: string | null;
  actif: boolean;
}

export interface AdresseFournisseur {
  id: number;
  libelle: string;
  adresse: string;
  ville: string | null;
  code_postal: string | null;
  region: string | null;
  pays: string | null;
  enlevement: boolean;
  principale: boolean;
  instructions: string | null;
  actif: boolean;
}

export interface CompteBancaireFournisseur {
  id: number;
  banque: string;
  agence: string | null;
  titulaire: string | null;
  numero_compte: string | null;
  iban: string | null;
  bic: string | null;
  devise: string | null;
  principal: boolean;
  notes: string | null;
  actif: boolean;
  updated_at: string;
  auteur_modification: { id: number; nom: string } | null;
}

export async function getFicheFournisseur(id: number): Promise<FicheFournisseur> {
  return fetchApi(`/fournisseurs/${id}/fiche`);
}

export async function getSyntheseFournisseur(id: number): Promise<SyntheseFournisseur> {
  return fetchApi(`/fournisseurs/${id}/synthese`);
}

export async function getHistoriqueFournisseur(id: number, params: { du?: string; au?: string; recherche?: string; page?: number }): Promise<HistoriqueFournisseur> {
  const query = new URLSearchParams();
  Object.entries(params).forEach(([cle, valeur]) => { if (valeur) query.append(cle, String(valeur)); });
  return fetchApi(`/fournisseurs/${id}/historique-achats?${query.toString()}`);
}

export async function getProduitsFournis(id: number): Promise<ProduitFourni[]> {
  return fetchApi(`/fournisseurs/${id}/produits`);
}

export type SaisieProduitFourni = Pick<ProduitFourni, 'reference_fournisseur' | 'prix_achat' | 'delai_livraison' | 'fournisseur_principal' | 'unite_achat' | 'qte_par_unite_achat'>;

export async function enregistrerProduitFourni(fournisseurId: number, produitId: number, donnees: SaisieProduitFourni, nouveau: boolean): Promise<void> {
  await fetchApi(nouveau ? `/fournisseurs/${fournisseurId}/produits` : `/fournisseurs/${fournisseurId}/produits/${produitId}`, {
    method: nouveau ? 'POST' : 'PUT',
    body: JSON.stringify(nouveau ? { ...donnees, produit_id: produitId } : donnees),
  });
}

export async function retirerProduitFourni(fournisseurId: number, produitId: number): Promise<void> {
  await fetchApi(`/fournisseurs/${fournisseurId}/produits/${produitId}`, { method: 'DELETE' });
}

// === COMMANDES FOURNISSEUR ===
export interface ComFourLigne {
  id?: number;
  com_four_entete_id?: number;
  produit_id: number;
  produit?: Produit;
  quantite_commandee: number;
  quantite_recue: number;
  /** Reste abandonné : le fournisseur ne le livrera pas (ligne ou commande clôturée). */
  quantite_abandonnee?: number;
  unite_achat?: string | null;
  qte_par_unite_achat?: number;
  prix_unitaire: number;
  montant_ligne: number;
  /** Commande de la ligne (chargée avec les réceptions : numéro, fournisseur, devise et taux). */
  commande?: ComFourEntete;
}

export interface ComFourEntete {
  id: number;
  numero: string;
  fournisseur_id: number;
  fournisseur?: Fournisseur;
  date_commande: string;
  date_livraison_prevue?: string;
  statut: 'brouillon' | 'envoyee' | 'partielle' | 'complete' | 'annulee';
  /** Jour et auteur de l'envoi au fournisseur (bouton « Valider et envoyer » ou courriel). */
  envoyee_le?: string | null;
  envoyee_par?: number | null;
  auteur_envoi?: { id: number; nom: string } | null;
  montant_total: number;
  devise?: string;
  taux_change?: number;
  notes?: string;
  /** Multi-entrepôts : l'entrepôt où livrer (« Livrer à ») ; vide, l'adresse de l'entreprise. */
  entrepot_id?: number | null;
  entrepot?: { id: number; code: string; nom: string; adresse?: string | null; ville?: string | null } | null;
  /** Transporteur : celui du fournisseur par défaut (phase 1.11b). */
  transporteur_id?: number | null;
  transporteur?: { id: number; nom: string; url_suivi?: string | null } | null;
  created_by: number;
  lignes?: ComFourLigne[];
  created_at?: string;
  updated_at?: string;
}

export async function getCommandesFournisseur(params?: {
  statut?: string;
  fournisseur_id?: number;
  transporteur_id?: number;
  search?: string;
  page?: number;
}): Promise<PageResultats<ComFourEntete>> {
  const searchParams = new URLSearchParams();
  if (params?.statut) searchParams.append('statut', params.statut);
  if (params?.fournisseur_id) searchParams.append('fournisseur_id', params.fournisseur_id.toString());
  if (params?.transporteur_id) searchParams.append('transporteur_id', String(params.transporteur_id));
  if (params?.search) searchParams.append('search', params.search);
  if (params?.page) searchParams.append('page', String(params.page));
  return fetchApi(`/commandes-fournisseur?${searchParams}`);
}

export async function getCommandeFournisseur(id: number): Promise<ComFourEntete> {
  return fetchApi(`/commandes-fournisseur/${id}`);
}

/** Prix proposé pour un produit : dernier prix payé au fournisseur, sinon prix convenu de son catalogue (A5). */
export interface PrixAchatPropose {
  prix: number | null;
  origine: 'dernier_paye' | 'convenu' | null;
  /** Numéro de la commande du dernier prix payé. */
  document: string | null;
}

/** Prix proposés des produits, dans l'unité d'achat chez ce fournisseur et dans la devise de la commande. */
export async function getPrixAchatProposes(fournisseurId: number, produits: number[], devise: string, tauxChange: number): Promise<Record<string, PrixAchatPropose>> {
  const parametres = new URLSearchParams({ fournisseur_id: String(fournisseurId), devise, taux_change: String(tauxChange) });
  produits.forEach(id => parametres.append('produits[]', String(id)));
  return fetchApi(`/commandes-fournisseur/prix-proposes?${parametres}`);
}

export async function createCommandeFournisseur(data: {
  fournisseur_id: number;
  date_commande: string;
  date_livraison_prevue?: string;
  notes?: string;
  devise?: string;
  taux_change?: number;
  entrepot_id?: number | null;
  /** Transporteur ; non transmis, celui du fournisseur (phase 1.11b). */
  transporteur_id?: number | null;
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

// === PIÈCES JOINTES ===
export type EntitePieceJointe =
  | 'client' | 'fournisseur' | 'produit' | 'employe'
  | 'devis' | 'commande_client' | 'facture' | 'bon_livraison' | 'commande_fournisseur' | 'facture_fournisseur' | 'encaissement' | 'debourse' | 'transaction_bancaire';

/**
 * Droit (module, action) qui permet d'ajouter, de modifier ou de retirer les pièces de chaque type de fiche :
 * le droit de modifier la fiche (miroir de PiecesJointes::DROITS côté serveur). Les voir demande de voir la fiche.
 */
export const DROITS_PIECES_JOINTES: Record<EntitePieceJointe, [string, string]> = {
  client: ['clients', 'modifier'],
  fournisseur: ['fournisseurs', 'modifier'],
  produit: ['produits', 'modifier'],
  employe: ['employes', 'modifier'],
  devis: ['devis', 'modifier'],
  commande_client: ['commandes_client', 'modifier'],
  facture: ['factures', 'modifier'],
  bon_livraison: ['bons_livraison', 'modifier'],
  commande_fournisseur: ['commandes_fournisseur', 'modifier'],
  facture_fournisseur: ['factures_fournisseurs', 'modifier'],
  encaissement: ['encaissements', 'modifier'],
  debourse: ['debourses', 'modifier'],
  transaction_bancaire: ['banque', 'saisir'],
};

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

/** Contenu d'une pièce jointe, pour l'afficher dans l'écran (aperçu d'une image). */
export async function getContenuPieceJointe(id: number): Promise<Blob> {
  return fetchApi<Blob>(`/pieces-jointes/${id}/fichier`, { responseType: 'blob', headers: { Accept: '*/*' }, timeoutMs: 120000 });
}

// === RECEPTIONS ===
export interface ReceptionLigne {
  id: number;
  /** Document de la réception (vide pour les réceptions enregistrées avant leur numérotation). */
  reception_id?: number | null;
  reception?: { id: number; numero: string; bon_livraison?: string | null } | null;
  com_four_ligne_id: number;
  date_reception: string;
  quantite_recue: number;
  /** Coût unitaire facturé (devise de la commande) ; vide = prix commandé. */
  cout_unitaire?: number | string | null;
  annulee?: boolean;
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
  page?: number;
  per_page?: number;
}): Promise<PageResultats<ReceptionLigne>> {
  const searchParams = new URLSearchParams();
  if (params?.commande_id) searchParams.append('commande_id', params.commande_id.toString());
  if (params?.date_debut) searchParams.append('date_debut', params.date_debut);
  if (params?.date_fin) searchParams.append('date_fin', params.date_fin);
  if (params?.page) searchParams.append('page', String(params.page));
  if (params?.per_page) searchParams.append('per_page', String(params.per_page));
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

// ============================================
// GESTION CLIENTS & VENTES
// ============================================

// Types
export interface Client extends EnteteCommerciale {
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
  /** Condition de paiement (référentiel), reprise par les factures du client. */
  condition_paiement_id?: number | null;
  /** Frais de livraison proposé sur ses documents de vente. */
  frais_livraison_id?: number | null;
  /** Devise du client (null : celle de l'entreprise), reprise par ses documents de vente. */
  devise?: string | null;
  /** Fiche du client de passage : ses coordonnées se notent sur chaque document. */
  de_passage?: boolean;
  /** Route de livraison (itinéraire du référentiel) et rang de passage sur cette route. */
  itineraire_id?: number | null;
  sequence_livraison?: number | null;
  actif: boolean;
  conditions_paiement?: ConditionPaiement[];
  categorie_id?: number | null;
  categorie?: { id: number; nom: string } | null;
  favori?: boolean;
}

/** Coordonnées du client de passage notées sur un devis, une commande ou une facture. */
export interface CoordonneesPassage {
  passage_nom?: string | null;
  passage_telephone?: string | null;
  passage_courriel?: string | null;
  passage_adresse?: string | null;
}

/** Représentant commercial d'un client, d'un devis, d'une commande ou d'une facture (phase 1.11a). */
export interface EnteteCommerciale {
  representant_id?: number | null;
  representant?: { id: number; nom: string } | null;
  /** Transporteur (phase 1.11b) : celui du client par défaut, suit les conversions. */
  transporteur_id?: number | null;
  transporteur?: { id: number; nom: string; url_suivi?: string | null } | null;
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

export interface ComClientEntete extends CoordonneesPassage, EnteteCommerciale {
  /** Numéro de suivi de l'envoi (connaissement), repris par la facture puis le bon de livraison. */
  numero_suivi?: string | null;
  recapitulatif_taxes?: RecapitulatifTaxe[];
  id: number;
  numero: string;
  client_id: number;
  client?: Client;
  date_commande: string;
  date_livraison_souhaitee?: string;
  statut: 'brouillon' | 'en_attente' | 'acceptee' | 'refusee' | 'facturee' | 'annulee';
  /** Devise de la commande (null : celle de l'entreprise) et son taux. */
  devise?: string | null;
  taux_change?: number | string;
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
  /** Unité de vente de la ligne (la caisse de 12) et son facteur ; vides : l'unité de stock. */
  unite?: string | null;
  facteur?: number | null;
  prix_unitaire_ht: number;
  taux_tva: number;
  remise_ligne: number;
  montant_ht: number;
  montant_ttc: number;
}

export interface Facture extends CoordonneesPassage, EnteteCommerciale {
  /** Numéro de suivi de l'envoi (connaissement), repris par le bon de livraison. */
  numero_suivi?: string | null;
  /** Taux de commission du représentant, figé à la création de la facture (%). */
  taux_commission?: number | string | null;
  recapitulatif_taxes?: RecapitulatifTaxe[];
  id: number;
  numero: string;
  commande_id?: number;
  client_id: number;
  client?: Client;
  facture_mere_id?: number;
  date_facture: string;
  date_echeance?: string;
  notes?: string | null;
  statut: 'brouillon' | 'emise' | 'partiellement_payee' | 'payee' | 'creditee' | 'annulee';
  montant_ht: number;
  montant_tva: number;
  montant_ttc: number;
  montant_paye: number;
  /** Crédits des notes de crédit imputés sur la facture. */
  montant_credite?: number | string;
  /** Escomptes pour paiement rapide accordés à ses paiements. */
  montant_escompte?: number | string;
  condition_paiement_id?: number | null;
  condition_paiement?: { id: number; libelle: string; escompte_pourcentage: string | null; escompte_jours: number | null } | null;
  /** Devise de la facture (null : celle de l'entreprise) et son taux vers la devise de l'entreprise. */
  devise?: string | null;
  taux_change?: number | string;
  reste_a_payer: number;
  lignes?: FactureLigne[];
  echeances?: FactureEcheance[];
  bon_livraison?: BonLivraison;
  /** Notes de crédit établies sur la facture (détail). */
  avoirs?: { id: number; numero: string | null; statut: string; date_avoir: string; montant_ttc: string }[];
  /** Retours de marchandise de la facture. */
  retours?: { id: number; numero: string | null; statut: string; date_retour: string }[];
}

/** Produit d'une ligne de document, tel que renvoyé par le détail (même s'il a été retiré du catalogue). */
export interface ProduitLigne {
  id: number;
  numero?: string;
  description: string;
  mesure?: string;
}

export interface FactureLigne {
  id: number;
  facture_id: number;
  produit_id: number;
  produit?: ProduitLigne;
  quantite: number;
  /** Unité de vente de la ligne (la caisse de 12) et son facteur ; vides : l'unité de stock. */
  unite?: string | null;
  facteur?: number | null;
  prix_unitaire_ht: number;
  taux_tva: number;
  montant_ht: number;
  montant_tva: number;
  montant_ttc: number;
  /** Texte sous la désignation du produit (période d'un abonnement…). */
  description?: string | null;
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
  notes_livraison?: string | null;
  /** Signature électronique : image (dans le détail seulement), signataire, moment ; a_signature dans les listes. */
  signature_client?: string | null;
  signataire_nom?: string | null;
  signe_le?: string | null;
  a_signature?: boolean;
  /** Nombre de colis du bon (dans les listes). */
  colis_count?: number;
  /** Transporteur et numéro de suivi (phase 1.11b), repris de la facture ; aucun pour un retrait par le client. */
  transporteur_id?: number | null;
  transporteur?: { id: number; nom: string; url_suivi?: string | null } | null;
  numero_suivi?: string | null;
}

export interface BonLivraisonLigne {
  id: number;
  bon_id: number;
  produit_id: number;
  produit?: ProduitLigne;
  quantite_a_livrer: number;
  quantite_preparee: number;
  quantite_livree: number;
  /** Unité de la ligne (celle facturée : la caisse de 12) ; vide : l'unité de stock. */
  unite?: string | null;
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
  /** Route suivie (itinéraire du référentiel) : ses bons prêts s'ajoutent d'un coup, dans l'ordre de passage. */
  itineraire_id?: number | null;
  itineraire?: { id: number; code: string; nom: string } | null;
  /** Détail : charge estimée (poids des produits encore à livrer) et lignes de matières dangereuses à bord. */
  poids_total_kg?: number;
  matieres_dangereuses?: number;
  /** Avancement du chargement du camion (dans le détail). */
  chargement?: ResumeChargement;
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
export async function getClients(params?: { search?: string; actif?: boolean; categorie_id?: number; favoris?: boolean; representant_id?: number; transporteur_id?: number; page?: number }): Promise<PageResultats<Client>> {
  const queryParams = new URLSearchParams();
  if (params?.representant_id) queryParams.append('representant_id', String(params.representant_id));
  if (params?.transporteur_id) queryParams.append('transporteur_id', String(params.transporteur_id));
  if (params?.search) queryParams.append('search', params.search);
  if (params?.actif !== undefined) queryParams.append('actif', String(params.actif));
  if (params?.categorie_id) queryParams.append('categorie_id', String(params.categorie_id));
  if (params?.favoris) queryParams.append('favoris', '1');
  if (params?.page) queryParams.append('page', String(params.page));
  return fetchApi(`/clients?${queryParams.toString()}`);
}

export async function getClientsActifs(): Promise<Client[]> {
  return fetchApi('/clients/actifs');
}

/** Fiche du client de passage de l'entreprise (ventes au comptoir), créée à sa première utilisation. */
export async function getClientDePassage(): Promise<Client> {
  return fetchApi('/clients/de-passage');
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

// Fiche client complète : synthèse, historique d'achats, contacts, adresses, remarques, catégories, favoris
export interface FicheClient extends Client {
  contacts_count: number;
  adresses_count: number;
  remarques_count: number;
  references_count: number;
}

/** Référence d'un produit chez un client (phase 1.2c) : reprise sur ses documents et dans son espace client. */
export interface ReferenceProduitClient {
  id: number;
  produit_id: number;
  reference: string;
  description: string | null;
  produit?: { id: number; numero: string; description: string; mesure: string | null } | null;
}

export async function getReferencesProduitsClient(clientId: number): Promise<ReferenceProduitClient[]> {
  return fetchApi(`/clients/${clientId}/references-produits`);
}

export interface SyntheseClient {
  chiffre_affaires: { total: number; annee_courante: number; annee_precedente: number };
  factures: {
    nombre: number;
    panier_moyen: number;
    premiere: string | null;
    derniere: { id: number; numero: string; date: string; montant_ttc: number } | null;
  };
  encours: { montant: number; maximum: number | null; en_retard: { nombre: number; montant: number } };
  commandes: { en_cours: number; nombre: number };
  devis: { en_cours: number; nombre: number };
  ventes_mensuelles: { mois: string; montant_ht: number }[];
  produits_les_plus_achetes: { produit_id: number; numero: string | null; description: string | null; quantite: number; montant_ht: number; factures: number; dernier_achat: string | null }[];
}

export interface LigneHistoriqueAchat {
  id: number;
  date: string;
  facture_id: number;
  facture_numero: string;
  produit_id: number;
  produit_numero: string | null;
  produit_description: string | null;
  quantite: number;
  prix_unitaire_ht: number;
  remise_ligne: number;
  montant_ht: number;
}

export interface HistoriqueAchats {
  data: LigneHistoriqueAchat[];
  current_page: number;
  last_page: number;
  total: number;
  totaux: { quantite: number; montant_ht: number };
}

export interface ContactClient {
  id: number;
  nom: string;
  fonction: string | null;
  telephone: string | null;
  telephone_secondaire: string | null;
  email: string | null;
  principal: boolean;
  recoit_factures: boolean;
  recoit_commandes: boolean;
  notes: string | null;
  actif: boolean;
}

export interface AdresseClient {
  id: number;
  libelle: string;
  adresse: string;
  ville: string | null;
  code_postal: string | null;
  region: string | null;
  pays: string | null;
  livraison: boolean;
  principale: boolean;
  instructions: string | null;
  actif: boolean;
}

/** Remarque datée d'une fiche (client, fournisseur…). */
export interface RemarqueFiche {
  id: number;
  texte: string;
  epinglee: boolean;
  auteur_id: number | null;
  auteur: { id: number; nom: string } | null;
  created_at: string;
}

export type RemarqueClient = RemarqueFiche;

export interface CategorieClient {
  id: number;
  nom: string;
  description: string | null;
  actif: boolean;
  clients_count?: number;
}

export async function getFicheClient(id: number): Promise<FicheClient> {
  return fetchApi(`/clients/${id}/fiche`);
}

export async function getSyntheseClient(id: number): Promise<SyntheseClient> {
  return fetchApi(`/clients/${id}/synthese`);
}

export async function getHistoriqueAchatsClient(id: number, params: { du?: string; au?: string; recherche?: string; page?: number }): Promise<HistoriqueAchats> {
  const query = new URLSearchParams();
  Object.entries(params).forEach(([cle, valeur]) => { if (valeur) query.append(cle, String(valeur)); });
  return fetchApi(`/clients/${id}/historique-achats?${query.toString()}`);
}

export async function changerCategorieClient(id: number, categorieId: number | null): Promise<Client> {
  return fetchApi(`/clients/${id}/categorie`, { method: 'PUT', body: JSON.stringify({ categorie_id: categorieId }) });
}

export async function basculerFavoriClient(id: number, favori: boolean): Promise<{ favori: boolean }> {
  return fetchApi(`/clients/${id}/favori`, { method: favori ? 'POST' : 'DELETE' });
}

/**
 * Listes rattachées à une fiche (contacts, adresses, remarques, comptes…), toutes sur le même modèle de
 * routes : `chemin` vaut par exemple `/clients/12/contacts` ou `/fournisseurs/5/comptes-bancaires`.
 */
export async function getListeFiche<T>(chemin: string): Promise<T[]> {
  return fetchApi(chemin);
}

export async function enregistrerElementFiche<T>(chemin: string, donnees: Partial<T>, id?: number): Promise<T> {
  return fetchApi(id ? `${chemin}/${id}` : chemin, {
    method: id ? 'PUT' : 'POST',
    body: JSON.stringify(donnees),
  });
}

export async function supprimerElementFiche(chemin: string, id: number): Promise<void> {
  await fetchApi(`${chemin}/${id}`, { method: 'DELETE' });
}

export async function getCategoriesClients(): Promise<CategorieClient[]> {
  return fetchApi('/categories-clients');
}

export async function enregistrerCategorieClient(donnees: Partial<CategorieClient>, id?: number): Promise<CategorieClient> {
  return fetchApi(id ? `/categories-clients/${id}` : '/categories-clients', { method: id ? 'PUT' : 'POST', body: JSON.stringify(donnees) });
}

export async function supprimerCategorieClient(id: number): Promise<void> {
  await fetchApi(`/categories-clients/${id}`, { method: 'DELETE' });
}

// Commandes Clients API
export async function getCommandesClient(params?: { statut?: string; client_id?: number; recherche?: string; representant_id?: number; transporteur_id?: number; page?: number }): Promise<PageResultats<ComClientEntete>> {
  const queryParams = new URLSearchParams();
  if (params?.representant_id) queryParams.append('representant_id', String(params.representant_id));
  if (params?.transporteur_id) queryParams.append('transporteur_id', String(params.transporteur_id));
  if (params?.statut) queryParams.append('statut', params.statut);
  if (params?.recherche) queryParams.append('recherche', params.recherche);
  if (params?.client_id) queryParams.append('client_id', String(params.client_id));
  if (params?.page) queryParams.append('page', String(params.page));
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
  representant_id?: number | null;
  transporteur_id?: number | null;
  numero_suivi?: string | null;
} & CoordonneesPassage): Promise<ComClientEntete> {
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
export async function getFactures(params?: { statut?: string; statuts?: string; recherche?: string; client_id?: number; representant_id?: number; transporteur_id?: number; date_debut?: string; date_fin?: string; page?: number; per_page?: number }): Promise<PageResultats<Facture>> {
  const queryParams = new URLSearchParams();
  if (params?.representant_id) queryParams.append('representant_id', String(params.representant_id));
  if (params?.transporteur_id) queryParams.append('transporteur_id', String(params.transporteur_id));
  if (params?.statut) queryParams.append('statut', params.statut);
  if (params?.statuts) queryParams.append('statuts', params.statuts);
  if (params?.recherche) queryParams.append('recherche', params.recherche);
  if (params?.client_id) queryParams.append('client_id', String(params.client_id));
  if (params?.date_debut) queryParams.append('date_debut', params.date_debut);
  if (params?.date_fin) queryParams.append('date_fin', params.date_fin);
  if (params?.page) queryParams.append('page', String(params.page));
  if (params?.per_page) queryParams.append('per_page', String(params.per_page));
  return fetchApi(`/factures?${queryParams.toString()}`);
}

export async function getFacture(id: number): Promise<Facture> {
  return fetchApi(`/factures/${id}`);
}

/** Facture d'une commande acceptée, datée du jour de l'usager (`date`, sa date locale). */
export async function creerFactureDepuisCommande(commandeId: number, date?: string): Promise<Facture> {
  return fetchApi(`/factures/commande/${commandeId}`, { method: 'POST', body: JSON.stringify({ date }) });
}

export async function emettreFacture(id: number): Promise<Facture> {
  return fetchApi(`/factures/${id}/emettre`, { method: 'POST' });
}

export async function enregistrerPaiement(id: number, data: {
  montant: number;
  date_paiement: string;
  mode_paiement: string;
  reference?: string;
  /** Escompte pour paiement rapide : le paiement règle alors tout le reste. */
  escompte?: boolean;
  /** Caisse du poste : le paiement entre dans sa session ouverte. */
  caisse_id?: number;
  /** Mode « carte_cadeau » : le numéro de la carte. */
  carte_cadeau?: string;
}): Promise<Facture> {
  return fetchApi(`/factures/${id}/paiement`, { method: 'POST', body: JSON.stringify(data) });
}

/** Escompte pour paiement rapide possible pour un paiement de la facture à une date. */
export interface PropositionEscompte {
  applicable: boolean;
  condition?: string;
  pourcentage?: number;
  jours?: number;
  date_limite?: string;
  escompte?: number;
  a_payer?: number;
}

export async function getEscompteFacture(id: number, date: string): Promise<PropositionEscompte> {
  return fetchApi(`/factures/${id}/escompte?date=${encodeURIComponent(date)}`);
}

export async function creerBonLivraison(factureId: number): Promise<BonLivraison> {
  return fetchApi(`/factures/${factureId}/creer-bl`, { method: 'POST' });
}

export type SaisieFacture = {
  client_id: number;
  date_facture: string;
  condition_paiement_id?: number | null;
  devise?: string;
  taux_change?: number;
  date_echeance?: string;
  notes?: string;
  lignes: { produit_id: number; quantite: number; prix_unitaire_ht: number; taux_tva?: number; remise_ligne?: number; unite?: string | null }[];
  representant_id?: number | null;
  transporteur_id?: number | null;
  numero_suivi?: string | null;
} & CoordonneesPassage;

export async function createFacture(data: SaisieFacture): Promise<Facture> {
  return fetchApi('/factures', { method: 'POST', body: JSON.stringify(data) });
}

/** Modifie une facture brouillon : en-tête et toutes ses lignes, comme à la création. */
export async function updateFacture(id: number, data: SaisieFacture): Promise<Facture> {
  return fetchApi(`/factures/${id}`, { method: 'PUT', body: JSON.stringify(data) });
}

export async function deleteFacture(id: number): Promise<void> {
  return fetchApi(`/factures/${id}`, { method: 'DELETE' });
}

// Bons de Livraison API
export async function getBonsLivraison(params?: { statut?: string; recherche?: string; transporteur_id?: number; page?: number; per_page?: number; sans_tournee?: boolean }): Promise<PageResultats<BonLivraison>> {
  const queryParams = new URLSearchParams();
  if (params?.statut) queryParams.append('statut', params.statut);
  if (params?.transporteur_id) queryParams.append('transporteur_id', String(params.transporteur_id));
  if (params?.recherche) queryParams.append('recherche', params.recherche);
  if (params?.page) queryParams.append('page', String(params.page));
  if (params?.per_page) queryParams.append('per_page', String(params.per_page));
  if (params?.sans_tournee) queryParams.append('sans_tournee', '1');
  return fetchApi(`/bons-livraison?${queryParams.toString()}`);
}

export async function getBonLivraison(id: number): Promise<BonLivraison> {
  return fetchApi(`/bons-livraison/${id}`);
}

/** Transporteur et numéro de suivi d'un bon (tant qu'il n'est ni livré ni annulé). */
export async function updateExpeditionBonLivraison(id: number, data: { transporteur_id: number | null; numero_suivi: string | null }): Promise<BonLivraison> {
  return fetchApi(`/bons-livraison/${id}/expedition`, { method: 'PUT', body: JSON.stringify(data) });
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

/** Exemplaires à sortir d'une ligne de bon : ses lots (le premier à périmer d'abord) ou ses numéros de série en stock. */
export interface ExemplairesLigneBL {
  ligne_id: number;
  suivi: 'lot' | 'serie';
  /** Unités de stock par unité livrée (une caisse de 12 : 12). */
  facteur: number;
  lots: { id: number; numero: string; date_peremption: string | null; solde: number }[];
  series: string[];
}

export async function getExemplairesBL(id: number): Promise<ExemplairesLigneBL[]> {
  return fetchApi(`/bons-livraison/${id}/exemplaires`);
}

export async function enregistrerLivraison(id: number, data: {
  /** Produits suivis : les lots qui sortent (sinon les premiers à périmer) et les numéros de série livrés. */
  lignes: { id: number; quantite_livree: number; lots?: { lot_id: number; quantite: number }[]; numeros_serie?: string[] }[];
  signature_client?: string;
  signataire_nom?: string;
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
export async function getTournees(params?: { statut?: string; date?: string; page?: number }): Promise<PageResultats<Tournee>> {
  const queryParams = new URLSearchParams();
  if (params?.statut) queryParams.append('statut', params.statut);
  if (params?.date) queryParams.append('date', params.date);
  if (params?.page) queryParams.append('page', String(params.page));
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
  itineraire_id?: number;
}): Promise<Tournee> {
  return fetchApi('/tournees', { method: 'POST', body: JSON.stringify(data) });
}

/** Range les arrêts d'une tournée planifiée par rang de passage des clients. */
export async function ordonnerTournee(id: number): Promise<Tournee> {
  return fetchApi(`/tournees/${id}/ordonner`, { method: 'POST' });
}

/** Ajoute à une tournée planifiée les bons prêts des clients de sa route, dans l'ordre de passage. */
export async function ajouterRouteTournee(id: number): Promise<Tournee & { message: string; ajoutes: number }> {
  return fetchApi(`/tournees/${id}/ajouter-route`, { method: 'POST' });
}

/** Ordre de passage des arrêts fixé à la main. */
export async function ordreTournee(id: number, ordre: { bon_livraison_id: number; ordre_livraison: number }[]): Promise<Tournee> {
  return fetchApi(`/tournees/${id}/ordre`, { method: 'PUT', body: JSON.stringify({ ordre }) });
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

// === PRIX ET REMISES ===
export interface ListePrix {
  id: number;
  nom: string;
  description: string | null;
  ajustement_pourcentage: number;
  precision: number;
  actif: boolean;
  prix_produits_count?: number;
  clients_count?: number;
}

export interface LigneGrillePrix {
  id: number;
  numero: string;
  description: string;
  categorie: string | null;
  actif: boolean;
  prix_base: number | null;
  prix: Record<string, { prix: number | null; saisi: boolean; formule: number | null }>;
}

export interface GrillePrix {
  data: LigneGrillePrix[];
  current_page: number;
  last_page: number;
  total: number;
  listes: ListePrix[];
  categories: string[];
}

export type TypeReglePrix = 'prix_fixe' | 'remise_pourcentage' | 'remise_montant';

export interface ReglePrix {
  id: number;
  libelle: string;
  client_id: number | null;
  /** Catégorie de clients visée (vide : tous les clients). */
  categorie_client_id: number | null;
  liste_prix_id: number | null;
  produit_id: number | null;
  categorie_produit: string | null;
  quantite_min: number;
  date_debut: string | null;
  date_fin: string | null;
  type: TypeReglePrix;
  valeur: number;
  actif: boolean;
  client?: { id: number; code: string; raison_sociale: string } | null;
  categorie_client?: { id: number; nom: string } | null;
  produit?: { id: number; numero: string; description: string } | null;
  liste_prix?: { id: number; nom: string } | null;
}

export interface PrixCalcule {
  produit_id: number;
  quantite: number;
  prix_base: number | null;
  liste: { id: number; nom: string } | null;
  prix_liste: number | null;
  prix: number | null;
  remise_pourcentage: number;
  origine: 'base' | 'liste' | 'remise_client' | 'regle';
  regle: { id: number; libelle: string } | null;
  candidats: { origine: PrixCalcule['origine']; libelle: string | null; prix: number | null; regle_id?: number }[];
  /** Unité de vente de la ligne (la caisse de 12) et son facteur ; absents : l'unité de stock du produit. */
  unite?: string | null;
  facteur?: number | null;
}

export async function getListesPrix(): Promise<ListePrix[]> {
  return fetchApi('/listes-prix');
}

export async function enregistrerListePrix(donnees: Partial<ListePrix>, id?: number): Promise<ListePrix> {
  return fetchApi(id ? `/listes-prix/${id}` : '/listes-prix', { method: id ? 'PUT' : 'POST', body: JSON.stringify(donnees) });
}

export async function supprimerListePrix(id: number): Promise<void> {
  await fetchApi(`/listes-prix/${id}`, { method: 'DELETE' });
}

export async function getClientsListePrix(id: number): Promise<{ id: number; code: string; raison_sociale: string; ville: string }[]> {
  return fetchApi(`/listes-prix/${id}/clients`);
}

export async function rattacherClientListePrix(clientId: number, listePrixId: number | null): Promise<void> {
  await fetchApi(`/clients/${clientId}/liste-prix`, { method: 'PUT', body: JSON.stringify({ liste_prix_id: listePrixId }) });
}

export async function getGrillePrix(params: { recherche?: string; categorie?: string; page?: number }): Promise<GrillePrix> {
  const query = new URLSearchParams();
  Object.entries(params).forEach(([cle, valeur]) => { if (valeur) query.append(cle, String(valeur)); });
  return fetchApi(`/grille-prix?${query.toString()}`);
}

export async function enregistrerGrillePrix(modifications: { produit_id: number; liste_prix_id: number | null; prix: number | null }[]): Promise<{ message: string }> {
  return fetchApi('/grille-prix', { method: 'PUT', body: JSON.stringify({ modifications }) });
}

export async function getReglesPrix(params: { statut?: string; recherche?: string }): Promise<{ data: ReglePrix[]; total: number }> {
  const query = new URLSearchParams();
  Object.entries(params).forEach(([cle, valeur]) => { if (valeur) query.append(cle, String(valeur)); });
  return fetchApi(`/regles-prix?${query.toString()}`);
}

export async function enregistrerReglePrix(donnees: Partial<ReglePrix>, id?: number): Promise<ReglePrix> {
  return fetchApi(id ? `/regles-prix/${id}` : '/regles-prix', { method: id ? 'PUT' : 'POST', body: JSON.stringify(donnees) });
}

export async function supprimerReglePrix(id: number): Promise<void> {
  await fetchApi(`/regles-prix/${id}`, { method: 'DELETE' });
}

export async function calculerPrix(donnees: { client_id?: number; date?: string; lignes: { produit_id: number; quantite?: number; unite?: string | null }[] }): Promise<PrixCalcule[]> {
  return fetchApi('/prix/calculer', { method: 'POST', body: JSON.stringify(donnees) });
}

// === RÉVISIONS DE PRIX (phase 1.2d) ===
export type StatutRevisionPrix = 'brouillon' | 'planifiee' | 'appliquee' | 'annulee';
/** Prix visés par un ajustement : le prix de base, les prix saisis d'une liste, ou les deux sur toutes les listes actives. */
export type CibleRevisionPrix = 'base' | 'liste' | 'toutes';
export type TypeAjustementPrix = 'pourcentage' | 'montant';

export interface RevisionPrix {
  id: number;
  nom: string;
  notes: string | null;
  date_effet: string | null;
  statut: StatutRevisionPrix;
  categorie: string | null;
  cible: CibleRevisionPrix | null;
  liste_prix_id: number | null;
  liste: string | null;
  type_ajustement: TypeAjustementPrix | null;
  valeur_ajustement: number | null;
  lignes_count: number;
  cree_par: string | null;
  created_at: string | null;
  updated_at: string | null;
  planifiee_le: string | null;
  appliquee_le: string | null;
  /** Vide pour une révision appliquée : par la tâche planifiée. */
  appliquee_par: string | null;
  annulee_le: string | null;
  annulee_par: string | null;
}

/** Nouveau prix d'une révision : le prix de base (liste vide) ou le prix saisi d'un produit dans une liste. */
export interface LigneRevisionPrix {
  id: number | null;
  produit_id: number;
  numero: string | null;
  description: string | null;
  categorie: string | null;
  produit_actif: boolean;
  liste_prix_id: number | null;
  liste: string | null;
  /** Prix en place ; pour une révision appliquée, le prix qu'elle a remplacé. */
  prix_actuel: number | null;
  nouveau_prix: number;
  /** Pour un prix de base : listes actives dont le prix du produit suit la formule (il change avec le prix de base). */
  formules: number[];
}

export interface RevisionPrixDetail extends RevisionPrix {
  lignes: LigneRevisionPrix[];
}

export interface ListeRevisionsPrix {
  data: RevisionPrix[];
  /** Tâche planifiée qui applique les révisions à leur date. */
  tache: { active: boolean; prochaine_execution: string | null };
  listes: Pick<ListePrix, 'id' | 'nom' | 'ajustement_pourcentage' | 'precision'>[];
  categories: string[];
}

export interface ReglageAjustementPrix {
  categorie?: string | null;
  cible: CibleRevisionPrix;
  liste_prix_id?: number | null;
  type: TypeAjustementPrix;
  valeur: number;
  inclure_inactifs?: boolean;
}

export interface SaisieRevisionPrix {
  nom: string;
  notes: string | null;
  date_effet: string | null;
  categorie: string | null;
  cible: CibleRevisionPrix | null;
  liste_prix_id: number | null;
  type_ajustement: TypeAjustementPrix | null;
  valeur_ajustement: number | null;
  lignes: { produit_id: number; liste_prix_id: number | null; nouveau_prix: number }[];
}

export async function getRevisionsPrix(): Promise<ListeRevisionsPrix> {
  return fetchApi('/revisions-prix');
}

export async function getRevisionPrix(id: number): Promise<RevisionPrixDetail> {
  return fetchApi(`/revisions-prix/${id}`);
}

/** Nouveaux prix d'un ajustement à partir des prix en place : rien n'est enregistré. */
export async function apercuRevisionPrix(reglage: ReglageAjustementPrix): Promise<{ lignes: LigneRevisionPrix[]; total: number }> {
  return fetchApi('/revisions-prix/apercu', { method: 'POST', body: JSON.stringify(reglage) });
}

export async function enregistrerRevisionPrix(donnees: SaisieRevisionPrix, id?: number): Promise<RevisionPrixDetail> {
  return fetchApi(id ? `/revisions-prix/${id}` : '/revisions-prix', { method: id ? 'PUT' : 'POST', body: JSON.stringify(donnees) });
}

export async function planifierRevisionPrix(id: number, dateEffet: string): Promise<RevisionPrixDetail> {
  return fetchApi(`/revisions-prix/${id}/planifier`, { method: 'POST', body: JSON.stringify({ date_effet: dateEffet }) });
}

export async function appliquerRevisionPrix(id: number): Promise<RevisionPrixDetail & { message: string }> {
  return fetchApi(`/revisions-prix/${id}/appliquer`, { method: 'POST' });
}

export async function annulerRevisionPrix(id: number): Promise<RevisionPrixDetail> {
  return fetchApi(`/revisions-prix/${id}/annuler`, { method: 'POST' });
}

export async function supprimerRevisionPrix(id: number): Promise<void> {
  await fetchApi(`/revisions-prix/${id}`, { method: 'DELETE' });
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
  /** Document de transport des matières dangereuses : téléphone d'urgence et déclaration de l'expéditeur. */
  md_telephone_urgence?: string | null;
  md_declaration?: string | null;
  /** Numéro de facture attribué à l'émission : un brouillon porte un numéro provisoire « BR-… ». */
  numero_facture_a_l_emission?: boolean;
  /** Transporteur exigé sur les devis, commandes, factures et commandes fournisseur saisis (s'il y en a un d'actif). */
  transporteur_obligatoire?: boolean;
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
  | 'types_location'
  | 'caisses'
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
export type TypeCourriel = 'devis' | 'commande_client' | 'facture' | 'avoir' | 'demande_acompte' | 'relance' | 'relance_devis' | 'bon_livraison' | 'commande_fournisseur' | 'retour_fournisseur' | 'rapport' | 'general';
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
  /** Boutons Accepter / Refuser dans les courriels des devis. */
  devis_reponse_en_ligne?: boolean;
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
/** Types d'envoi d'un document (ni le courriel général, ni un rapport de la bibliothèque). */
export type TypeDocumentCourriel = Exclude<TypeCourriel, 'general' | 'rapport'>;
/** Ce qu'un envoi de l'historique joint : un document, ou un rapport de la bibliothèque. */
export type TypeEnvoiCourriel = TypeDocumentCourriel | 'rapport';
export type StatutCourriel = 'en_attente' | 'en_cours' | 'envoye' | 'echec';

export interface PreparationCourriel {
  type: TypeEnvoiCourriel;
  /** Absent pour un rapport (son titre est dans document_numero). */
  document_id: number | null;
  document_numero: string;
  langue: LangueCourriel;
  modele_personnalise: boolean;
  destinataires: string[];
  objet: string;
  contenu: string;
  piece_jointe: string | null;
  /** Statut du document : un devis ou un bon de commande au brouillon change de statut à l'envoi. */
  statut_document?: string | null;
}

export interface EnvoiCourriel {
  id: number;
  type: TypeEnvoiCourriel;
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
  // Devis : l'adresse du site de l'espace client, où le client répond au devis en ligne.
  const parametres = new URLSearchParams({ type, document_id: String(documentId), langue, ...(type === 'devis' ? { origine: URL_ESPACE_CLIENT } : {}) });
  return fetchApi(`/courriels/preparer?${parametres}`);
}

export async function envoyerCourriel(courriel: NouveauCourriel): Promise<EnvoiCourriel> {
  return fetchApi('/courriels/envois', { method: 'POST', body: JSON.stringify(courriel) });
}

export async function getEnvoisCourriel(filtres: { type?: TypeEnvoiCourriel; document_id?: number; rapport?: string; statut?: StatutCourriel; page?: number } = {}): Promise<PageEnvoisCourriel> {
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

export async function getTauxChange(from: string, to: string): Promise<{ from: string; to: string; taux: number; date: string; source?: 'identique' | 'entreprise' | 'frankfurter' }> {
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
  /** Unité de vente de la ligne (la caisse de 12) et son facteur ; vides : l'unité de stock. */
  unite?: string | null;
  facteur?: number | null;
  prix_unitaire: number;
  montant_ligne: number;
}

export interface DevisRecord extends CoordonneesPassage, EnteteCommerciale {
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
  /** Commande issue de la conversion : un devis ne se convertit qu'une fois. */
  commande_id?: number | null;
  commande?: { id: number; numero: string } | null;
  /** Facture issue du devis facturé directement (sans commande). */
  facture_id?: number | null;
  facture?: { id: number; numero: string } | null;
  /** Devise du devis (null : celle de l'entreprise) et son taux. */
  devise?: string | null;
  taux_change?: number | string;
  /** Envoi au client, puis sa réponse : en ligne (nom, commentaire) ou notée à la main. */
  envoye_le?: string | null;
  reponse_le?: string | null;
  reponse_en_ligne?: boolean;
  reponse_nom?: string | null;
  reponse_commentaire?: string | null;
  /** Relances envoyées au client et date de la dernière. */
  relances?: number;
  derniere_relance_le?: string | null;
}

export async function getDevis(params?: { statut?: string; search?: string; representant_id?: number; transporteur_id?: number; page?: number }): Promise<PageResultats<DevisRecord>> {
  const qs = new URLSearchParams();
  if (params?.representant_id) qs.append('representant_id', String(params.representant_id));
  if (params?.transporteur_id) qs.append('transporteur_id', String(params.transporteur_id));
  if (params?.statut) qs.append('statut', params.statut);
  if (params?.search) qs.append('search', params.search);
  if (params?.page) qs.append('page', String(params.page));
  return fetchApi(`/devis?${qs.toString()}`);
}

export async function createDevis(data: {
  client_id: number;
  date_devis: string;
  date_validite: string;
  notes?: string;
  devise?: string;
  taux_change?: number;
  lignes: { produit_id: number; quantite: number; prix_unitaire: number }[];
  representant_id?: number | null;
  transporteur_id?: number | null;
} & CoordonneesPassage): Promise<DevisRecord> {
  return fetchApi('/devis', { method: 'POST', body: JSON.stringify(data) });
}

export async function updateDevis(id: number, data: {
  client_id: number;
  date_devis: string;
  date_validite: string;
  notes?: string;
  lignes: { id?: number; produit_id: number; quantite: number; prix_unitaire: number }[];
  representant_id?: number | null;
  transporteur_id?: number | null;
} & CoordonneesPassage): Promise<DevisRecord> {
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

/** Commande tirée d'un devis accepté, datée du jour de l'usager (`date`, sa date locale). */
export async function convertirDevisEnCommande(id: number, date?: string): Promise<{ message: string; commande: ComClientEntete }> {
  return fetchApi(`/devis/${id}/convertir`, { method: 'POST', body: JSON.stringify({ date }) });
}

/** Facture brouillon issue d'un devis accepté, sans commande (un devis ne se convertit qu'une fois). */
/** Facture tirée directement d'un devis accepté, datée du jour de l'usager (`date`, sa date locale). */
export async function facturerDevis(id: number, date?: string): Promise<{ message: string; facture: Facture }> {
  return fetchApi(`/devis/${id}/facturer`, { method: 'POST', body: JSON.stringify({ date }) });
}

/** Nouveau devis brouillon repris de celui-ci, daté du jour. */
export async function copierDevis(id: number): Promise<{ message: string; devis: DevisRecord }> {
  return fetchApi(`/devis/${id}/copier`, { method: 'POST' });
}

/** Lien de réponse en ligne du devis (le client l'accepte ou le refuse sur le site de l'espace client). */
export async function lienReponseDevis(id: number): Promise<{ url: string; devis: DevisRecord }> {
  return fetchApi(`/devis/${id}/lien-reponse`, { method: 'POST', body: JSON.stringify({ origine: URL_ESPACE_CLIENT }) });
}

export async function deleteDevis(id: number): Promise<{ message: string }> {
  return fetchApi(`/devis/${id}`, { method: 'DELETE' });
}

export async function consommerNumero(entite: string): Promise<{ numero: string }> {
  return fetchApi(`/configurations/${entite}/consommer`, { method: 'POST' });
}
