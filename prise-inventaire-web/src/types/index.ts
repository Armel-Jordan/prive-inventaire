export interface Employe {
  id?: number;
  numero: string;
  nom: string;
}

export interface Produit {
  id?: number;
  numero: string;
  description: string;
  mesure: string;
  type: string;
}

export interface Secteur {
  id?: number;
  code: string;
  nom: string;
  description?: string;
}

export interface InventaireScan {
  id: number;
  numero: string;
  type: string;
  quantite: number;
  unite_mesure: string;
  employe: string;
  secteur: string;
  date_saisie: string;
  scanneur?: string;
  created_at?: string;
  updated_at?: string;
}

export interface MouvementInventaire {
  id: number;
  scan_id: number;
  type_mouvement: 'ENTREE' | 'SORTIE' | 'CORRECTION';
  quantite_avant: number;
  quantite_apres: number;
  motif?: string;
  utilisateur: string;
  date_mouvement: string;
}
