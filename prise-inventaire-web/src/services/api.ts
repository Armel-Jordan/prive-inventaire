import type { Employe, Produit, Secteur, InventaireScan } from '@/types';

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

// === PRODUITS ===
export async function getProduits(): Promise<Produit[]> {
  if (MOCK_MODE) return mockProduits;
  return fetchApi<Produit[]>('/produits');
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
