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

export async function deleteCommandeFournisseur(id: number): Promise<void> {
  await fetchApi(`/commandes-fournisseur/${id}`, { method: 'DELETE' });
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
