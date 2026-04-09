import type { Employe, Produit, Secteur, InventaireScan, AdminUser } from '@/types';

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

// Mock data pour le développement (à remplacer par les vrais appels API)
// Mettre à false quand l'API Laravel est prête
const MOCK_MODE = import.meta.env.VITE_MOCK_MODE === 'true';

const STORAGE_KEY = 'prise_auth';

function getAuthHeaders(): Record<string, string> {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  };

  const stored = localStorage.getItem(STORAGE_KEY);
  if (stored) {
    try {
      const data = JSON.parse(stored);
      if (data.token) {
        headers['Authorization'] = `Bearer ${data.token}`;
      }
      if (data.tenant?.slug) {
        headers['X-Tenant-Slug'] = data.tenant.slug;
      }
    } catch {
      // ignore
    }
  }

  return headers;
}

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

async function fetchApi<T>(endpoint: string, options?: RequestInit): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${endpoint}`, {
    headers: getAuthHeaders(),
    ...options,
  });

  if (!response.ok) {
    throw new Error(`Erreur API: ${response.status}`);
  }

  return response.json();
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

export interface ComClientEntete {
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

export async function getParametres(): Promise<TenantParametres> {
  return fetchApi('/parametres');
}

export async function updateParametres(data: TenantParametres): Promise<{ message: string; parametres: TenantParametres }> {
  return fetchApi('/parametres', { method: 'PUT', body: JSON.stringify(data) });
}

export async function genererNumero(entite: string): Promise<{ numero: string; prochain: number }> {
  return fetchApi(`/configurations/${entite}/generer`);
}

export async function consommerNumero(entite: string): Promise<{ numero: string }> {
  return fetchApi(`/configurations/${entite}/consommer`, { method: 'POST' });
}
